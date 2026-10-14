<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Mail\AgenciaViajes\CotizacionClienteMail;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\CotizacionEnvio;
use App\Services\AgenciaViajes\AlternativaPdfService;
use App\Services\AgenciaViajes\CondicionesGeneralesPdfService;
use App\Services\AgenciaViajes\CorreoAgenciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

// Envío de una alternativa de cotización por correo al cliente
// (plan-modulo-envio-correo-cotizacion.md §4-§5). Síncrono (D3): el usuario ve
// el resultado real en el momento. Toda solicitud que llega a intentar el envío
// deja una fila en cotizacion_envios, también si falla.
//
// Quién puede enviar (D6): permiso cotizaciones.enviar_correo + la misma regla
// de fila que ver la cotización (CotizacionPolicy::view): sin ver_todas, solo
// las cotizaciones propias.
class CotizacionEnvioController extends Controller
{
    private const MAX_DESTINATARIOS = 5;

    public function __construct(
        private CorreoAgenciaService $correo,
        private AlternativaPdfService $alternativaPdf,
        private CondicionesGeneralesPdfService $condicionesPdf,
    ) {
    }

    // GET correo-cotizaciones/estado — lo usa el cotizador para decidir si
    // muestra el botón "Enviar por correo".
    public function estado(): JsonResponse
    {
        return response()->json($this->resumenEstado());
    }

    // GET alternativas/{id}/envio-correo — datos para abrir el modal.
    public function preparar(string $id): JsonResponse
    {
        $alternativa = Alternativa::with('cotizacion.cliente')->findOrFail($id);
        $this->authorize('view', $alternativa->cotizacion);

        $config = $this->correo->configuracion();
        $email = $alternativa->cotizacion->cliente?->email;

        return response()->json([
            ...$this->resumenEstado(),
            ...$this->correo->borrador($alternativa, auth('api')->user()),
            'para' => $email ? [$email] : [],
            'adjuntar_condiciones' => (bool) $config->adjuntar_condiciones_por_defecto,
            'estado_alternativa' => $alternativa->estado,
            'puede_marcar_enviada' => $alternativa->estado === 'borrador',
        ]);
    }

    // GET alternativas/{id}/envios-correo — historial debajo de la alternativa.
    public function historial(string $id): JsonResponse
    {
        $alternativa = Alternativa::with('cotizacion')->findOrFail($id);
        $this->authorize('view', $alternativa->cotizacion);

        $envios = CotizacionEnvio::with('usuario:id,name,surname')
            ->where('alternativa_id', $alternativa->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CotizacionEnvio $e) => [
                'id' => $e->id,
                'fecha' => $e->created_at,
                'usuario' => trim(($e->usuario?->name ?? '') . ' ' . ($e->usuario?->surname ?? '')),
                'para' => $e->para,
                'cc' => $e->cc ?? [],
                'remitente' => $e->remitente,
                'asunto' => $e->asunto,
                'adjuntos' => collect($e->adjuntos ?? [])->pluck('nombre')->all(),
                'estado' => $e->estado,
                'error' => $e->error,
            ]);

        return response()->json(['envios' => $envios]);
    }

    // POST alternativas/{id}/enviar-correo
    public function enviar(Request $request, string $id): JsonResponse
    {
        $alternativa = Alternativa::with('cotizacion.cliente')->findOrFail($id);
        $this->authorize('view', $alternativa->cotizacion);

        if (! $this->correo->habilitado()) {
            return response()->json([
                'code' => 403,
                'message' => 'El envío de cotizaciones por correo no está habilitado para tu empresa. Consulta con el soporte de UmboSystem.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'para' => 'required|array|min:1|max:' . self::MAX_DESTINATARIOS,
            'para.*' => 'required|email:rfc|max:190',
            'cc' => 'nullable|array|max:' . self::MAX_DESTINATARIOS,
            'cc.*' => 'email:rfc|max:190',
            'asunto' => 'required|string|max:255',
            'mensaje' => 'nullable|string|max:10000',
            'adjuntar_cotizacion' => 'required|boolean',
            'adjuntar_condiciones' => 'required|boolean',
            'marcar_enviada' => 'required|boolean',
        ], [
            'para.required' => 'Indica al menos un destinatario.',
            'para.max' => 'Máximo ' . self::MAX_DESTINATARIOS . ' destinatarios.',
            'para.*.email' => 'Revisa los correos de destino: alguno no es válido.',
            'cc.max' => 'Máximo ' . self::MAX_DESTINATARIOS . ' direcciones en copia.',
            'cc.*.email' => 'Revisa los correos en copia: alguno no es válido.',
            'asunto.required' => 'El asunto es obligatorio.',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $datos = $validator->validated();
        $mensaje = trim((string) ($datos['mensaje'] ?? ''));

        if (! $datos['adjuntar_cotizacion'] && ! $datos['adjuntar_condiciones'] && $mensaje === '') {
            return response()->json(['code' => 422, 'message' => 'Agrega un mensaje o al menos un adjunto.'], 422);
        }

        if ($alternativa->estado === 'descartada') {
            return response()->json(['code' => 422, 'message' => 'Esta alternativa está descartada: no se puede enviar.'], 422);
        }

        if ($this->correo->enviadosHoy() >= $this->correo->limiteDiario()) {
            return response()->json([
                'code' => 422,
                'message' => 'Se alcanzó el límite de ' . $this->correo->limiteDiario() . ' envíos por día. Se renueva a medianoche.',
            ], 422);
        }

        $remitente = $this->correo->remitente();
        $bcc = $this->correo->copiaOculta();
        $cc = array_values($datos['cc'] ?? []);
        $para = array_values($datos['para']);

        $fila = [
            'cotizacion_id' => $alternativa->cotizacion_id,
            'alternativa_id' => $alternativa->id,
            'usuario_id' => auth('api')->id(),
            'tipo' => CotizacionEnvio::TIPO_COTIZACION,
            'para' => $para,
            'cc' => $cc,
            'bcc' => $bcc,
            'remitente' => "{$remitente['nombre']} <{$remitente['email']}>",
            'modo_remitente' => $remitente['modo'],
            'asunto' => $datos['asunto'],
            'cuerpo' => $mensaje,
        ];

        // Los PDFs se generan ANTES de enviar: si la alternativa no se puede
        // imprimir (grupo de hotel sin opción elegida), no sale nada.
        try {
            $adjuntos = [];
            if ($datos['adjuntar_cotizacion']) {
                $adjuntos[] = $this->alternativaPdf->contenido((string) $alternativa->id);
            }
            if ($datos['adjuntar_condiciones']) {
                $adjuntos[] = $this->condicionesPdf->contenido();
            }
        } catch (ValidationException $e) {
            $motivo = collect($e->errors())->flatten()->first() ?? $e->getMessage();
            $envio = CotizacionEnvio::create($fila + ['estado' => CotizacionEnvio::ESTADO_FALLIDO, 'error' => $motivo]);

            return response()->json(['code' => 422, 'message' => $motivo, 'envio_id' => $envio->id], 422);
        }

        $fila['adjuntos'] = array_map(static fn (array $a) => ['nombre' => $a['nombre'], 'bytes' => strlen($a['bytes'])], $adjuntos);

        $mail = new CotizacionClienteMail(
            $remitente['email'],
            $remitente['nombre'],
            $remitente['reply_to'],
            $datos['asunto'],
            $mensaje,
            $adjuntos,
        );

        try {
            $messageId = $this->correo->enviar($mail, $para, $cc, $bcc);
        } catch (Throwable $e) {
            Log::warning('Envío de cotización por correo falló', ['alternativa_id' => $alternativa->id, 'error' => $e->getMessage()]);
            $envio = CotizacionEnvio::create($fila + ['estado' => CotizacionEnvio::ESTADO_FALLIDO, 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            return response()->json([
                'code' => 502,
                'message' => 'No se pudo enviar el correo. Intenta de nuevo en unos minutos; si continúa, avisa al soporte.',
                'envio_id' => $envio->id,
            ], 502);
        }

        $envio = CotizacionEnvio::create($fila + ['estado' => CotizacionEnvio::ESTADO_ENVIADO, 'message_id' => $messageId]);

        // D4: marcar como enviada solo si sigue en borrador; nunca retrocede ni
        // pisa una alternativa ya aceptada.
        if ($datos['marcar_enviada'] && $alternativa->estado === 'borrador') {
            $alternativa->update(['estado' => 'enviada'] + $alternativa->camposAlMarcarEnviada());
        }

        return response()->json([
            'code' => 200,
            'message' => 'Cotización enviada a ' . implode(', ', $para) . '.',
            'envio_id' => $envio->id,
            'estado_alternativa' => $alternativa->fresh()->estado,
            'enviados_hoy' => $this->correo->enviadosHoy(),
        ]);
    }

    private function resumenEstado(): array
    {
        $remitente = $this->correo->remitente();
        $dominio = $this->correo->dominio();

        return [
            'habilitado' => $this->correo->habilitado(),
            'limite_diario' => $this->correo->limiteDiario(),
            'enviados_hoy' => $this->correo->enviadosHoy(),
            'remitente' => $remitente,
            'dominio' => $dominio ? ['dominio' => $dominio->dominio, 'estado' => $dominio->brevo_estado] : null,
        ];
    }
}
