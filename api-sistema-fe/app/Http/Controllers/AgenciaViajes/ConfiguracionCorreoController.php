<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Mail\AgenciaViajes\CotizacionClienteMail;
use App\Models\AgenciaViajes\ConfiguracionCorreo;
use App\Models\AgenciaViajes\CotizacionEnvio;
use App\Services\AgenciaViajes\CorreoAgenciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

// Sección "Correo" de Configuración de Agencia
// (plan-modulo-envio-correo-cotizacion.md §3.2). Permiso agencia.configuracion,
// el mismo de las demás secciones de esa pantalla.
class ConfiguracionCorreoController extends Controller
{
    public function __construct(private CorreoAgenciaService $correo)
    {
    }

    public function show(): JsonResponse
    {
        $config = $this->correo->configuracion();
        $dominio = $this->correo->dominio();

        return response()->json([
            'configuracion_correo' => [
                'remitente_nombre' => $config->remitente_nombre,
                'remitente_local' => $config->remitente_local,
                'reply_to' => $config->reply_to,
                'copia_oculta_activa' => (bool) $config->copia_oculta_activa,
                'copia_oculta_email' => $config->copia_oculta_email,
                'asunto_plantilla' => $config->asunto_plantilla,
                'cuerpo_plantilla' => $config->cuerpo_plantilla,
                'firma' => $config->firma,
                'adjuntar_condiciones_por_defecto' => (bool) ($config->adjuntar_condiciones_por_defecto ?? true),
            ],
            'habilitado' => $this->correo->habilitado(),
            'limite_diario' => $this->correo->limiteDiario(),
            'enviados_hoy' => $this->correo->enviadosHoy(),
            'remitente' => $this->correo->remitente($config),
            'respaldo' => (string) config('mail.cotizaciones_respaldo'),
            'dominio' => $dominio ? ['dominio' => $dominio->dominio, 'estado' => $dominio->brevo_estado] : null,
            'variables' => CorreoAgenciaService::VARIABLES,
            'por_defecto' => [
                'asunto' => CorreoAgenciaService::ASUNTO_POR_DEFECTO,
                'cuerpo' => CorreoAgenciaService::CUERPO_POR_DEFECTO,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'remitente_nombre' => 'nullable|string|max:120',
            // Solo la parte antes de la @: el dominio lo impone el sistema (§6).
            'remitente_local' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._+-]+$/'],
            'reply_to' => 'nullable|email:rfc|max:190',
            'copia_oculta_activa' => 'required|boolean',
            'copia_oculta_email' => 'nullable|required_if:copia_oculta_activa,true|email:rfc|max:190',
            'asunto_plantilla' => 'nullable|string|max:255',
            'cuerpo_plantilla' => 'nullable|string|max:10000',
            'firma' => 'nullable|string|max:2000',
            'adjuntar_condiciones_por_defecto' => 'required|boolean',
        ], [
            'remitente_local.regex' => 'Escribe solo lo que va antes de la @ (letras, números, punto, guion).',
            'copia_oculta_email.required_if' => 'Indica a qué correo enviar la copia oculta.',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $datos = $validator->validated();
        if (isset($datos['remitente_local'])) {
            $datos['remitente_local'] = strtolower($datos['remitente_local']);
        }

        $config = ConfiguracionCorreo::first();
        if ($config) {
            $config->update($datos);
        } else {
            $config = ConfiguracionCorreo::create($datos);
        }

        return $this->show();
    }

    // POST configuracion-correo/prueba — envía un correo de ejemplo al propio
    // usuario, con la configuración guardada y sin adjuntos. Cuenta para el
    // límite diario y queda en el historial (tipo 'prueba').
    public function prueba(): JsonResponse
    {
        if (! $this->correo->habilitado()) {
            return response()->json([
                'code' => 403,
                'message' => 'El envío de cotizaciones por correo no está habilitado para tu empresa. Consulta con el soporte de UmboSystem.',
            ], 403);
        }

        $usuario = auth('api')->user();
        if (! $usuario?->email) {
            return response()->json(['code' => 422, 'message' => 'Tu usuario no tiene correo registrado.'], 422);
        }

        if ($this->correo->enviadosHoy() >= $this->correo->limiteDiario()) {
            return response()->json(['code' => 422, 'message' => 'Se alcanzó el límite de envíos de hoy.'], 422);
        }

        $config = $this->correo->configuracion();
        $remitente = $this->correo->remitente($config);
        $ejemplo = [
            'cliente' => 'Ana Pérez', 'codigo' => 'CDKM-0001', 'alternativa' => 'Opción 1',
            'destino' => 'Tarapoto', 'fecha_viaje' => '15/11/2026 al 18/11/2026',
            'vendedor' => trim($usuario->name . ' ' . $usuario->surname), 'vigencia' => '30/10/2026',
            'agencia' => $remitente['nombre'],
        ];
        $asunto = $this->correo->renderizar($config->asunto_plantilla ?: CorreoAgenciaService::ASUNTO_POR_DEFECTO, $ejemplo);
        $cuerpo = $this->correo->renderizar($config->cuerpo_plantilla ?: CorreoAgenciaService::CUERPO_POR_DEFECTO, $ejemplo);
        if ($config->firma) {
            $cuerpo .= "\n\n" . $this->correo->renderizar($config->firma, $ejemplo);
        }

        $fila = [
            'usuario_id' => $usuario->id,
            'tipo' => CotizacionEnvio::TIPO_PRUEBA,
            'para' => [$usuario->email],
            'remitente' => "{$remitente['nombre']} <{$remitente['email']}>",
            'modo_remitente' => $remitente['modo'],
            'asunto' => $asunto,
            'cuerpo' => $cuerpo,
        ];

        try {
            $messageId = $this->correo->enviar(
                new CotizacionClienteMail($remitente['email'], $remitente['nombre'], $remitente['reply_to'], $asunto, $cuerpo, [], true),
                [$usuario->email],
            );
        } catch (Throwable $e) {
            Log::warning('Correo de prueba de cotizaciones falló', ['error' => $e->getMessage()]);
            CotizacionEnvio::create($fila + ['estado' => CotizacionEnvio::ESTADO_FALLIDO, 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            return response()->json(['code' => 502, 'message' => 'No se pudo enviar el correo de prueba. Intenta de nuevo en unos minutos.'], 502);
        }

        CotizacionEnvio::create($fila + ['estado' => CotizacionEnvio::ESTADO_ENVIADO, 'message_id' => $messageId]);

        return response()->json(['code' => 200, 'message' => "Correo de prueba enviado a {$usuario->email}."]);
    }
}
