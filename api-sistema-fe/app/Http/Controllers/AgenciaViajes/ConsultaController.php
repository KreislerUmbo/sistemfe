<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\Client\Client;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Services\AgenciaViajes\AlternativaCreacionException;
use App\Services\AgenciaViajes\CotizacionCreacionService;
use App\Services\HoraPeru;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// T-059 (CRM C4, obs. 16) — «Nueva consulta»: registrar en un paso a alguien que pregunta (por
// WhatsApp, Facebook…). Usa un cliente que ya existe o crea uno sin documento, y crea la cotización
// con su «Alternativa A» por el mismo camino que «Nueva cotización» (CotizacionCreacionService).
// La nota queda como actividad y, si se pide, «Enviar cotización» agendada para hoy. Todo en una
// transacción: si la alternativa no se puede crear (tipo de cambio) no queda un cliente suelto.
class ConsultaController extends Controller
{
    use ValidaEnEspanol;

    // Pasajeros de una consulta: adultos nacionales de 30 años, como «+ Adulto(s)» de nueva.vue.
    // Las edades se corrigen después en el cotizador.
    private const EDAD_ADULTO = 30;

    private const MAX_PASAJEROS = 30;

    private const MAX_COINCIDENCIAS = 5;

    private const ATRIBUTOS = [
        'cliente_id' => 'cliente',
        'nombre' => 'nombre',
        'telefono' => 'teléfono',
        'origen_contacto_id' => 'origen del contacto',
        'destino' => 'destino',
        'fecha_viaje_desde' => 'fecha de inicio del viaje',
        'fecha_viaje_hasta' => 'fecha de fin del viaje',
        'pasajeros' => 'cantidad de pasajeros',
        'nota' => 'nota',
        'agendar_envio' => 'agendar envío',
        'moneda_cotizacion' => 'moneda',
        'tipo_cambio_valor' => 'tipo de cambio',
        'tipo_cambio_confirmado' => 'confirmación del tipo de cambio',
    ];

    public function __construct(private CotizacionCreacionService $cotizacionCreacion)
    {
    }

    // GET crm/consultas/coincidencias?telefono=&nombre= — clientes activos que podrían ser la misma
    // persona. El teléfono se compara solo por dígitos (los últimos 9 de lo escrito), así
    // «999 888 777» encuentra «999888777» y «+51 999888777»; el nombre, desde 3 letras.
    public function coincidencias(Request $request): JsonResponse
    {
        $digitos = substr(preg_replace('/\D/', '', (string) $request->query('telefono')), -9);
        $nombre = trim(preg_replace('/\s+/', ' ', (string) $request->query('nombre')));
        $porTelefono = strlen($digitos) >= 6;
        $porNombre = mb_strlen($nombre) >= 3;

        if (! $porTelefono && ! $porNombre) {
            return response()->json(['clientes' => []]);
        }

        $clientes = Client::query()
            ->where(fn ($q) => $q->whereNull('state')->orWhere('state', '!=', 2))
            ->where(function ($q) use ($porTelefono, $digitos, $porNombre, $nombre) {
                if ($porTelefono) {
                    $q->orWhereRaw("regexp_replace(COALESCE(phone, ''), '\\D', '', 'g') LIKE ?", ['%' . $digitos . '%']);
                }
                if ($porNombre) {
                    $q->orWhere('full_name', 'ilike', '%' . $nombre . '%');
                }
            })
            ->orderBy('full_name')
            ->limit(self::MAX_COINCIDENCIAS)
            ->get(['id', 'full_name', 'phone', 'type_document']);

        return response()->json(['clientes' => $clientes]);
    }

    // POST crm/consultas
    public function store(Request $request): JsonResponse
    {
        $usuario = auth('api')->user();
        // Sin cliente elegido se crea uno: hace falta poder registrar clientes.
        abort_unless($request->filled('cliente_id') || $usuario?->can('register_client'), 403);

        $validator = $this->validadorEs($request->all(), [
            'cliente_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'nombre' => 'required|string|max:250',
            'telefono' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+\-\s()]*$/'],
            'origen_contacto_id' => ['nullable', 'integer', Rule::exists('crm_origenes_contacto', 'id')->where('activo', true)],
            'destino' => 'required|string|max:250',
            'fecha_viaje_desde' => 'nullable|date',
            'fecha_viaje_hasta' => 'nullable|date|after_or_equal:fecha_viaje_desde',
            'pasajeros' => 'required|integer|min:1|max:' . self::MAX_PASAJEROS,
            'nota' => 'nullable|string|max:' . CrmActividad::NOTA_MAX,
            'agendar_envio' => 'nullable|boolean',
            'moneda_cotizacion' => 'nullable|in:PEN,USD',
            'tipo_cambio_valor' => 'nullable|numeric|min:0',
            'tipo_cambio_confirmado' => 'nullable|boolean',
        ], self::ATRIBUTOS);
        $validator->setCustomMessages(['telefono.regex' => 'El teléfono solo puede tener números, espacios, +, - y paréntesis.']);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $datos = $validator->validated();
        $nota = trim((string) ($datos['nota'] ?? ''));

        try {
            [$cotizacion, $clienteCreado] = DB::transaction(function () use ($datos, $usuario, $nota) {
                $clienteId = $datos['cliente_id'] ?? null;
                $clienteCreado = false;

                if (! $clienteId) {
                    // Sin documento, como ClienteRequest::prepareForValidation() con 'SND'.
                    $telefono = trim((string) ($datos['telefono'] ?? ''));
                    $clienteId = Client::create([
                        'type_client' => 1,
                        'type_document' => 'SND',
                        'n_document' => '00000000',
                        'cod_tipo_doc_sunat' => '0',
                        'full_name' => trim(preg_replace('/\s+/', ' ', $datos['nombre'])),
                        'phone' => $telefono !== '' ? $telefono : null,
                        'state' => 1,
                        'origen_contacto_id' => $datos['origen_contacto_id'] ?? null,
                    ])->id;
                    $clienteCreado = true;
                }

                $cotizacion = $this->cotizacionCreacion->crear([
                    'cliente_id' => $clienteId,
                    'destino' => $datos['destino'],
                    'fecha_viaje_desde' => $datos['fecha_viaje_desde'] ?? null,
                    'fecha_viaje_hasta' => $datos['fecha_viaje_hasta'] ?? null,
                    'pasajeros' => array_fill(0, (int) $datos['pasajeros'], [
                        'edad' => self::EDAD_ADULTO,
                        'residencia' => CotizacionPasajero::RESIDENCIA_NACIONAL,
                    ]),
                    'moneda_cotizacion' => $datos['moneda_cotizacion'] ?? null,
                    'tipo_cambio_valor' => $datos['tipo_cambio_valor'] ?? null,
                    'tipo_cambio_confirmado' => $datos['tipo_cambio_confirmado'] ?? false,
                    'origen_contacto_id' => $datos['origen_contacto_id'] ?? null,
                ], $usuario?->id);

                if ($nota !== '') {
                    CrmActividad::create([
                        'cliente_id' => $clienteId,
                        'referencia_tipo' => CrmActividad::REFERENCIA_COTIZACION,
                        'referencia_id' => $cotizacion->id,
                        'tipo' => CrmActividad::TIPO_NOTA,
                        'nota' => $nota,
                        'usuario_id' => $usuario?->id,
                    ]);
                }

                if (! empty($datos['agendar_envio'])) {
                    CrmAccion::create([
                        'cliente_id' => $clienteId,
                        'referencia_tipo' => CrmActividad::REFERENCIA_COTIZACION,
                        'referencia_id' => $cotizacion->id,
                        'tipo' => CrmAccion::TIPO_ENVIAR_COTIZACION,
                        'fecha' => HoraPeru::hoyTexto(),
                        'responsable_id' => $usuario?->id,
                        'creada_por' => $usuario?->id,
                    ]);
                }

                return [$cotizacion, $clienteCreado];
            });
        } catch (AlternativaCreacionException $e) {
            // La transacción ya deshizo el cliente y la cotización: nada a medias.
            return response()->json(['code' => 422, 'message' => $e->getMessage()] + $e->extra, 422);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Consulta registrada.',
            'cotizacion' => ['id' => $cotizacion->id, 'codigo' => $cotizacion->codigo],
            'cliente_creado' => $clienteCreado,
        ]);
    }
}
