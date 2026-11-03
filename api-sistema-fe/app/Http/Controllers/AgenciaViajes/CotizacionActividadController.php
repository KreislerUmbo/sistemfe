<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Concerns\AutorizaCotizacion;
use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionEnvio;
use App\Models\Client\CrmActividad;
use App\Services\Crm\AccionesCrm;
use App\Services\HoraPeru;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// T-050 (CRM C1) — el cotizador sobre el historial del cliente (crm_actividades):
// marcar una alternativa como enviada por WhatsApp u otro medio, y ver los contactos
// de una cotización. El envío por correo vive en CotizacionEnvioController.
class CotizacionActividadController extends Controller
{
    use AutorizaCotizacion;
    use ValidaEnEspanol;

    // Estados desde los que ya no se envía: la aceptada generó su reserva, la descartada y la
    // reemplazada quedaron fuera de la oferta.
    private const ESTADOS_SIN_ENVIO = ['aceptada', 'descartada', Alternativa::ESTADO_REEMPLAZADA];

    public function __construct(private readonly AccionesCrm $acciones)
    {
    }

    // POST alternativas/{id}/marcar-enviada — WhatsApp o "ya la envié por otro medio".
    // En borrador la pasa a enviada (mismo criterio que AlternativaController::update());
    // ya enviada, solo deja otra actividad: la fecha de envío no cambia.
    public function marcarEnviada(Request $request, string $id): JsonResponse
    {
        $alternativa = Alternativa::with('cotizacion')->findOrFail($id);
        $this->autorizarCotizacion('update', $alternativa);

        $validator = $this->validadorEs($request->all(), [
            'canal' => 'required|in:' . CrmActividad::CANAL_WHATSAPP . ',' . CrmActividad::CANAL_OTRO,
            'nota' => 'nullable|string|max:' . CrmActividad::NOTA_MAX,
            // T-051: la próxima acción propuesta ("Llamar en N días"); null = el vendedor la quitó.
            'proxima_accion' => 'nullable|array',
            ...AccionesCrm::reglas('proxima_accion.', false),
        ], ['canal' => 'medio de envío', 'nota' => 'nota']);
        $validator->setCustomMessages(AccionesCrm::mensajes('proxima_accion.'));

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        if (in_array($alternativa->estado, self::ESTADOS_SIN_ENVIO, true)) {
            return response()->json([
                'code' => 422,
                'message' => "Esta alternativa está {$alternativa->estado}: ya no se envía.",
            ], 422);
        }

        $datos = $validator->validated();
        $nota = trim((string) ($datos['nota'] ?? ''));

        DB::transaction(function () use ($alternativa, $datos, $nota) {
            if ($alternativa->estado === 'borrador') {
                $alternativa->update(['estado' => 'enviada'] + $alternativa->camposAlMarcarEnviada());
            }
            $alternativa->registrarEnvio($datos['canal'], $nota !== '' ? $nota : null);
            $this->acciones->crearAlEnviar($alternativa, $datos['proxima_accion'] ?? null);
        });

        $alternativa->refresh();

        return response()->json([
            'code' => 200,
            'message' => 'Alternativa marcada como enviada.',
            'estado_alternativa' => $alternativa->estado,
            'fecha_envio' => $alternativa->fecha_envio,
            'fecha_vencimiento' => $alternativa->fecha_vencimiento,
        ]);
    }

    // POST alternativas/{id}/extender-vigencia — T-053: nueva fecha de vencimiento de una enviada
    // (vigente o vencida), desde hoy en adelante. No cambia el estado: deja de verse «vencida»
    // porque estadoResumen() compara fecha_vencimiento con el ahora. Queda en el historial.
    public function extenderVigencia(Request $request, string $id): JsonResponse
    {
        $alternativa = Alternativa::with('cotizacion')->findOrFail($id);
        $this->autorizarCotizacion('update', $alternativa);

        $validator = $this->validadorEs($request->all(), [
            'fecha' => 'required|date_format:Y-m-d|after_or_equal:' . HoraPeru::hoyTexto(),
        ], ['fecha' => 'nueva fecha de vencimiento']);
        $validator->setCustomMessages(['fecha.after_or_equal' => 'La nueva fecha de vencimiento no puede ser anterior a hoy.']);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $cotizacion = $alternativa->cotizacion;
        $motivo = match (true) {
            $cotizacion->origen !== Cotizacion::ORIGEN_COTIZADOR => 'La vigencia solo se extiende en una cotización del Cotizador.',
            $cotizacion->estaPerdida() => 'La cotización está cerrada como perdida: reábrela para extender la vigencia.',
            $alternativa->estado !== 'enviada' => "Esta alternativa está {$alternativa->estado}: solo se extiende la vigencia de una enviada.",
            default => null,
        };
        if ($motivo) {
            return response()->json(['code' => 422, 'message' => $motivo], 422);
        }

        $fecha = $validator->validated()['fecha'];
        $anterior = $alternativa->fecha_vencimiento
            ? HoraPeru::deUtc($alternativa->fecha_vencimiento)->subSecond()->format('d/m/Y')
            : 'no tenía';
        $nueva = CarbonImmutable::parse($fecha)->format('d/m/Y');

        DB::transaction(function () use ($alternativa, $cotizacion, $fecha, $anterior, $nueva) {
            $alternativa->update(['fecha_vencimiento' => HoraPeru::finDiaUtc($fecha)]);
            CrmActividad::create([
                'cliente_id' => $cotizacion->cliente_id,
                'tipo' => CrmActividad::TIPO_ESTADO,
                'referencia_tipo' => CrmActividad::REFERENCIA_COTIZACION,
                'referencia_id' => $cotizacion->id,
                'alternativa_id' => $alternativa->id,
                'nota' => "Vigencia de «{$alternativa->nombre}» extendida hasta el {$nueva} (antes: {$anterior})",
                'usuario_id' => auth('api')->id(),
            ]);
        });

        return response()->json([
            'code' => 200,
            'message' => "Vigencia extendida hasta el {$nueva}.",
            'fecha_vencimiento' => $alternativa->fresh()->fecha_vencimiento,
        ]);
    }

    // GET cotizaciones/{id}/actividades — historial colapsable del editor. Suma los envíos por
    // correo fallidos (no son contactos con el cliente, pero el vendedor tiene que verlos).
    public function index(string $id): JsonResponse
    {
        $cotizacion = Cotizacion::with('alternativas:id,cotizacion_id,nombre')->findOrFail($id);
        $this->autorizarCotizacion('view', $cotizacion);

        $nombres = $cotizacion->alternativas->pluck('nombre', 'id');
        $nombreUsuario = fn ($u) => trim(($u?->name ?? '') . ' ' . ($u?->surname ?? ''));

        $actividades = CrmActividad::with('usuario:id,name,surname')
            ->deCotizacion($cotizacion->id)
            ->get()
            ->map(fn (CrmActividad $a) => [
                'id' => "actividad-{$a->id}",
                'tipo' => $a->tipo,
                'canal' => $a->canal,
                'alternativa' => $a->alternativa_id ? ($nombres[$a->alternativa_id] ?? null) : null,
                'nota' => $a->nota,
                'usuario' => $nombreUsuario($a->usuario),
                'fecha' => $a->created_at,
                'fallido' => false,
                'error' => null,
            ]);

        $fallidos = CotizacionEnvio::with('usuario:id,name,surname')
            ->where('cotizacion_id', $cotizacion->id)
            ->where('tipo', CotizacionEnvio::TIPO_COTIZACION)
            ->where('estado', CotizacionEnvio::ESTADO_FALLIDO)
            ->get()
            ->map(fn (CotizacionEnvio $e) => [
                'id' => "envio-{$e->id}",
                'tipo' => CrmActividad::TIPO_ENVIO,
                'canal' => CrmActividad::CANAL_CORREO,
                'alternativa' => $e->alternativa_id ? ($nombres[$e->alternativa_id] ?? null) : null,
                'nota' => 'Para: ' . implode(', ', $e->para ?? []),
                'usuario' => $nombreUsuario($e->usuario),
                'fecha' => $e->created_at,
                'fallido' => true,
                'error' => $e->error,
            ]);

        $historial = $actividades->concat($fallidos)->sortByDesc('fecha')->values();

        return response()->json(['actividades' => $historial]);
    }
}
