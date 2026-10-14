<?php

namespace App\Services\AgenciaViajes;

use App\Mail\AgenciaViajes\CotizacionClienteMail;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\ConfiguracionCorreo;
use App\Models\AgenciaViajes\CotizacionEnvio;
use App\Models\Central\TenantDominioCorreo;
use App\Models\Company;
use App\Models\User;
use App\Services\HoraPeru;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

// Envío de la cotización por correo (plan-modulo-envio-correo-cotizacion.md).
// Resuelve el remitente (dominio verificado de la agencia o respaldo de la
// plataforma), las plantillas, el límite diario y el transporte. Variante V1:
// un solo transporte (el SMTP de Brevo de la plataforma); mailerPara() es el
// punto donde se sumaría V2 (SMTP propio de la agencia) sin tocar el flujo.
class CorreoAgenciaService
{
    public const ASUNTO_POR_DEFECTO = 'Cotización {codigo} — {destino}';

    public const CUERPO_POR_DEFECTO = "Hola {cliente}:\n\n"
        . "Te enviamos la cotización {codigo} para tu viaje a {destino} ({fecha_viaje}).\n"
        . "Adjuntamos el detalle en PDF y nuestras condiciones generales del servicio.\n\n"
        . "La cotización es válida hasta el {vigencia}. Cualquier consulta, responde este correo.\n\n"
        . "Saludos,\n{vendedor}";

    public const VARIABLES = ['cliente', 'codigo', 'alternativa', 'destino', 'fecha_viaje', 'vendedor', 'vigencia', 'agencia'];

    public function configuracion(): ConfiguracionCorreo
    {
        return ConfiguracionCorreo::first() ?? new ConfiguracionCorreo([
            'adjuntar_condiciones_por_defecto' => true,
            'copia_oculta_activa' => false,
        ]);
    }

    public function habilitado(): bool
    {
        return (bool) tenant('correo_cotizaciones_habilitado');
    }

    public function limiteDiario(): int
    {
        return (int) (tenant('correo_limite_diario') ?? 50);
    }

    // Envíos exitosos de hoy (hora de Perú). Los fallidos no consumen cuota de
    // Brevo, por eso no cuentan. Regla de fechas: nunca whereDate().
    public function enviadosHoy(): int
    {
        $hoy = HoraPeru::hoyTexto();

        return CotizacionEnvio::where('estado', CotizacionEnvio::ESTADO_ENVIADO)
            ->where('created_at', '>=', HoraPeru::inicioDiaUtc($hoy))
            ->where('created_at', '<', HoraPeru::finDiaUtc($hoy))
            ->count();
    }

    public function dominio(): ?TenantDominioCorreo
    {
        $tenantId = tenant('id');

        return $tenantId ? TenantDominioCorreo::where('tenant_id', $tenantId)->first() : null;
    }

    /**
     * Remitente efectivo. El dominio sale SIEMPRE del dominio verificado del
     * tenant: el usuario solo elige lo que va antes de la @ (§6).
     *
     * @return array{email: string, nombre: string, modo: string, reply_to: ?string}
     */
    public function remitente(?ConfiguracionCorreo $config = null): array
    {
        $config ??= $this->configuracion();
        $empresa = Company::first();
        $nombre = trim((string) ($config->remitente_nombre ?: ($empresa?->razon_social_comercial ?: $empresa?->razon_social ?: 'Agencia')));
        $replyTo = $config->reply_to ?: ($empresa?->email ?: null);

        $dominio = $this->dominio();
        if ($dominio && $dominio->estaVerificado() && $config->remitente_local) {
            return [
                'email' => strtolower($config->remitente_local) . '@' . strtolower($dominio->dominio),
                'nombre' => $nombre,
                'modo' => CotizacionEnvio::MODO_DOMINIO_AGENCIA,
                'reply_to' => $replyTo,
            ];
        }

        return [
            'email' => (string) config('mail.cotizaciones_respaldo'),
            'nombre' => $nombre,
            'modo' => CotizacionEnvio::MODO_PLATAFORMA,
            'reply_to' => $replyTo,
        ];
    }

    public function copiaOculta(?ConfiguracionCorreo $config = null): array
    {
        $config ??= $this->configuracion();

        return $config->copia_oculta_activa && $config->copia_oculta_email ? [$config->copia_oculta_email] : [];
    }

    /** Variables de la plantilla para una alternativa, en texto ya formateado. */
    public function variables(Alternativa $alternativa, ?User $usuario = null): array
    {
        $alternativa->loadMissing(['cotizacion.cliente', 'cotizacion.vendedor', 'destinos.destinoAtractivo']);
        $cotizacion = $alternativa->cotizacion;
        $empresa = Company::first();

        $destinos = $alternativa->destinos
            ->map(fn ($d) => $d->destinoAtractivo?->nombre ?? $d->destino_texto ?? null)
            ->filter()->unique()->implode(', ');

        $vendedor = $cotizacion->vendedor ?? $usuario;
        $vigencia = $alternativa->fecha_vencimiento;
        if (! $vigencia) {
            $dias = ConfiguracionAgencia::first()?->dias_vigencia_cotizacion;
            $vigencia = $dias ? now()->addDays((int) $dias) : null;
        }

        return [
            'cliente' => trim((string) ($cotizacion->cliente?->full_name ?: 'cliente')),
            'codigo' => (string) ($cotizacion->codigo ?? $cotizacion->id),
            'alternativa' => (string) $alternativa->nombre,
            'destino' => $destinos ?: ((string) $cotizacion->destino ?: 'tu destino'),
            'fecha_viaje' => $this->rangoFechas($cotizacion->fecha_viaje_desde, $cotizacion->fecha_viaje_hasta),
            'vendedor' => trim(($vendedor?->name ?? '') . ' ' . ($vendedor?->surname ?? '')) ?: ($empresa?->razon_social_comercial ?? ''),
            'vigencia' => $vigencia ? HoraPeru::deUtc($vigencia)->format('d/m/Y') : 'consultar',
            'agencia' => (string) ($empresa?->razon_social_comercial ?: $empresa?->razon_social ?: ''),
        ];
    }

    public function renderizar(string $plantilla, array $variables): string
    {
        $reemplazos = [];
        foreach (self::VARIABLES as $clave) {
            $reemplazos['{' . $clave . '}'] = $variables[$clave] ?? '';
        }

        return strtr($plantilla, $reemplazos);
    }

    /** Asunto y mensaje propuestos para el modal de envío. */
    public function borrador(Alternativa $alternativa, ?User $usuario = null): array
    {
        $config = $this->configuracion();
        $variables = $this->variables($alternativa, $usuario);
        $cuerpo = $this->renderizar($config->cuerpo_plantilla ?: self::CUERPO_POR_DEFECTO, $variables);
        if ($config->firma) {
            $cuerpo .= "\n\n" . $this->renderizar($config->firma, $variables);
        }

        return [
            'asunto' => $this->renderizar($config->asunto_plantilla ?: self::ASUNTO_POR_DEFECTO, $variables),
            'mensaje' => $cuerpo,
        ];
    }

    // V1: el mailer por defecto de la plataforma (Brevo SMTP en producción,
    // `log` en local). V2 resolvería acá un transporte propio por tenant.
    public function mailerPara(): Mailer
    {
        return Mail::mailer();
    }

    /**
     * Envía y devuelve el Message-ID. Lanza la excepción del transporte si
     * falla (el llamador registra la fila 'fallido').
     */
    public function enviar(CotizacionClienteMail $mail, array $para, array $cc = [], array $bcc = []): ?string
    {
        $pendiente = $this->mailerPara()->to($para);
        if ($cc) {
            $pendiente->cc($cc);
        }
        if ($bcc) {
            $pendiente->bcc($bcc);
        }

        $enviado = $pendiente->send($mail);

        return $enviado?->getMessageId();
    }

    private function rangoFechas($desde, $hasta): string
    {
        if (! $desde) {
            return 'fecha por definir';
        }
        $texto = $desde->format('d/m/Y');
        if ($hasta && ! $hasta->equalTo($desde)) {
            $texto .= ' al ' . $hasta->format('d/m/Y');
        }

        return $texto;
    }
}
