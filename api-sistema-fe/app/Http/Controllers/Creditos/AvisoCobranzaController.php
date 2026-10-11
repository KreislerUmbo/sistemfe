<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Mail\Creditos\VerificarCorreoAvisosMail;
use App\Models\Company;
use App\Models\Creditos\CreditoAviso;
use App\Models\Creditos\CreditoAvisoPreferencia;
use App\Models\Creditos\CreditoConfiguracion;
use App\Models\PushSuscripcion;
use App\Models\User;
use App\Services\Creditos\Avisos\AvisoCobranzaService;
use App\Services\Creditos\Avisos\UrlFrontendTenant;
use App\Services\Creditos\Reloj;
use App\Services\Push\EnviadorPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Preferencias del aviso diario de cobranza de cada usuario (Fase 5): correo para avisos
 * (verificado por enlace firmado), canales, y "Enviar prueba". El envío programado lo hace el
 * comando creditos:aviso-cobranza-dia.
 */
class AvisoCobranzaController extends ControllerCreditos
{
    private const HORAS_ENLACE = 48;

    public function __construct(
        private readonly AvisoCobranzaService $avisos,
        private readonly UrlFrontendTenant $url,
        private readonly EnviadorPush $enviadorPush,
        private readonly Reloj $reloj,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->datos($this->usuario()));
    }

    public function update(Request $request): JsonResponse
    {
        $f = $request->validate([
            'correo' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'recibir_correo' => ['sometimes', 'boolean'],
            'recibir_push' => ['sometimes', 'boolean'],
        ]);
        $usuario = $this->usuario();
        $pref = CreditoAvisoPreferencia::de($usuario);
        $correoNuevo = array_key_exists('correo', $f) ? (mb_strtolower(trim((string) $f['correo'])) ?: null) : $pref->correo;
        $cambioCorreo = $correoNuevo !== $pref->correo;

        $pref->fill([...$f, 'correo' => $correoNuevo]);
        if ($cambioCorreo) {
            $pref->correo_verificado_en = null;   // un correo nuevo vuelve a confirmarse
        }
        $pref->save();
        if ($cambioCorreo && $correoNuevo !== null) {
            $this->enviarVerificacion($usuario, $pref);
        }

        return response()->json($this->datos($usuario));
    }

    public function reenviarVerificacion(): JsonResponse
    {
        $usuario = $this->usuario();
        $pref = CreditoAvisoPreferencia::de($usuario);
        if (! $pref->exists || ! $pref->correo) {
            throw new HttpException(422, 'Primero guarda un correo para avisos.');
        }
        if ($pref->correo_verificado_en) {
            throw new HttpException(422, 'Ese correo ya está confirmado.');
        }
        $this->enviarVerificacion($usuario, $pref);

        return response()->json(['mensaje' => "Enviamos el enlace de confirmación a {$pref->correo}."]);
    }

    /** "Enviar prueba": el aviso de hoy, ahora, a mi correo confirmado; no cuenta como el del día. */
    public function prueba(): JsonResponse
    {
        $usuario = $this->usuario();
        if (CreditoAvisoPreferencia::de($usuario)->correoUtilizable() === null) {
            throw new HttpException(422, 'Necesitas un correo para avisos confirmado y encendido.');
        }
        $estados = $this->avisos->avisar($usuario, $this->reloj->hoy(), CreditoConfiguracion::actual(), $this->url->agendaDeHoy(), prueba: true, canales: [CreditoAviso::CANAL_CORREO]);
        if ($estados[CreditoAviso::CANAL_CORREO] !== CreditoAviso::ESTADO_ENVIADO) {
            throw new HttpException(502, 'No se pudo enviar el correo de prueba. Intenta de nuevo en unos minutos.');
        }

        return response()->json(['mensaje' => 'Correo de prueba enviado.']);
    }

    /** Entrega 2: activa los avisos push en el dispositivo desde el que se llama (o lo actualiza). */
    public function suscribirPush(Request $request): JsonResponse
    {
        if (! $this->enviadorPush->configurado()) {
            throw new HttpException(503, 'Los avisos en el celular todavía no están configurados en el servidor.');
        }
        $f = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ]);
        $usuario = $this->usuario();
        // El mismo navegador con otro usuario (celular compartido): pasa al que inicia sesión ahora.
        PushSuscripcion::updateOrCreate(['endpoint_hash' => PushSuscripcion::hash($f['endpoint'])], [
            'user_id' => $usuario->id,
            'endpoint' => $f['endpoint'],
            'clave_p256dh' => $f['keys']['p256dh'],
            'clave_auth' => $f['keys']['auth'],
            'codificacion' => $f['contentEncoding'] ?? 'aes128gcm',
            'agente' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
        $pref = CreditoAvisoPreferencia::de($usuario);
        if (! $pref->recibir_push) {
            $pref->recibir_push = true;   // activar en un dispositivo es querer recibirlos
        }
        $pref->save();

        return response()->json($this->datos($usuario));
    }

    public function desuscribirPush(Request $request): JsonResponse
    {
        $f = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        $usuario = $this->usuario();
        PushSuscripcion::where('user_id', $usuario->id)->where('endpoint_hash', PushSuscripcion::hash($f['endpoint']))->delete();

        return response()->json($this->datos($usuario));
    }

    /** "Probar en mis dispositivos": el push de hoy, ahora; no cuenta como el del día. */
    public function pruebaPush(): JsonResponse
    {
        $usuario = $this->usuario();
        if (! PushSuscripcion::where('user_id', $usuario->id)->exists()) {
            throw new HttpException(422, 'Primero activa los avisos en este dispositivo.');
        }
        $estados = $this->avisos->avisar($usuario, $this->reloj->hoy(), CreditoConfiguracion::actual(), $this->url->agendaDeHoy(), prueba: true, canales: [CreditoAviso::CANAL_PUSH]);
        if ($estados[CreditoAviso::CANAL_PUSH] !== CreditoAviso::ESTADO_ENVIADO) {
            throw new HttpException(502, 'No se pudo entregar el aviso de prueba. Desactiva y vuelve a activar los avisos en este dispositivo.');
        }

        return response()->json(['mensaje' => 'Aviso de prueba enviado a tus dispositivos.']);
    }

    /** El usuario abrió la Agenda desde el aviso (?aviso= en la URL del push). */
    public function abierto(int $aviso): JsonResponse
    {
        CreditoAviso::whereKey($aviso)->where('user_id', $this->usuario()->id)->whereNull('abierto_en')
            ->update(['abierto_en' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Enlace del correo de confirmación (firmado, sin sesión). */
    public function verificar(Request $request): Response
    {
        $pref = CreditoAvisoPreferencia::where('user_id', (int) $request->query('u'))->first();
        $valido = $pref !== null && $pref->correo !== null
            && hash_equals(self::huella($pref->correo), (string) $request->query('c'));
        if ($valido && ! $pref->correo_verificado_en) {
            $pref->update(['correo_verificado_en' => now()]);
        }

        return response(self::pagina(
            $valido ? 'Correo confirmado' : 'Enlace no válido',
            $valido
                ? "Listo: {$pref->correo} recibirá el aviso diario de cobranza. Ya puedes cerrar esta pestaña."
                : 'Este enlace ya no corresponde al correo guardado. Pide uno nuevo desde tus preferencias de avisos.',
        ), $valido ? 200 : 410)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function enviarVerificacion(User $usuario, CreditoAvisoPreferencia $pref): void
    {
        $url = URL::temporarySignedRoute('creditos.avisos.verificar-correo', now()->addHours(self::HORAS_ENLACE), [
            'u' => $usuario->id,
            'c' => self::huella((string) $pref->correo),
        ]);
        try {
            Mail::to($pref->correo)->send(new VerificarCorreoAvisosMail(Company::first()?->razon_social ?: 'Créditos', $usuario->name, $url));
        } catch (\Throwable $e) {
            report($e);
            throw new HttpException(502, 'Guardamos el correo, pero no se pudo enviar el enlace de confirmación. Usa "Reenviar".');
        }
    }

    /** @return array<string, mixed> */
    private function datos(User $usuario): array
    {
        $pref = CreditoAvisoPreferencia::de($usuario);
        $config = CreditoConfiguracion::actual();

        return [
            // Lo que decidió el negocio (el cobrador no puede leer la configuración completa).
            'aviso_activo' => (bool) $config->avisos_activo,
            'aviso_hora' => (string) $config->avisos_hora,
            'correo' => $pref->correo,
            // Sin correo guardado se sugiere el de inicio de sesión (igual hay que confirmarlo).
            'correo_sugerido' => $pref->correo ? null : $usuario->email,
            'correo_verificado' => $pref->correo_verificado_en !== null,
            'recibir_correo' => (bool) $pref->recibir_correo,
            'recibir_push' => (bool) $pref->recibir_push,
            'pdf_adjunto' => $usuario->can(AvisoCobranzaService::PERMISO_ADJUNTO),
            // Entrega 2: null si el servidor no tiene claves VAPID (el push no está disponible).
            'push_clave_publica' => $this->enviadorPush->configurado() ? (string) config('services.webpush.public_key') : null,
            'push_dispositivos' => PushSuscripcion::where('user_id', $usuario->id)->orderByDesc('updated_at')
                ->get(['endpoint_hash', 'agente', 'ultimo_envio_en', 'created_at']),
            'ultimos' => CreditoAviso::where('user_id', $usuario->id)->orderByDesc('fecha')->orderByDesc('id')->limit(7)
                ->get(['fecha', 'canal', 'estado', 'detalle', 'cantidad_cobros', 'monto_esperado', 'enviado_en']),
        ];
    }

    /** Liga el enlace al correo guardado: si el correo cambia, el enlace viejo ya no sirve. */
    private static function huella(string $correo): string
    {
        return substr(hash_hmac('sha256', mb_strtolower($correo), (string) config('app.key')), 0, 32);
    }

    private static function pagina(string $titulo, string $mensaje): string
    {
        $titulo = e($titulo);
        $mensaje = e($mensaje);

        return <<<HTML
            <!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{$titulo}</title></head>
            <body style="margin:0;padding:24px 16px;background:#f4f5f7;font-family:Arial,sans-serif;color:#222">
            <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:8px;padding:20px"><h1 style="font-size:20px;margin:0 0 12px">{$titulo}</h1><p style="margin:0">{$mensaje}</p></div>
            </body></html>
            HTML;
    }
}
