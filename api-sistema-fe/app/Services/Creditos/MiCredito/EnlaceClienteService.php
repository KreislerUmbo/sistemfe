<?php

declare(strict_types=1);

namespace App\Services\Creditos\MiCredito;

use App\Models\Client\Client;
use App\Models\Creditos\CreditoEnlaceCliente;
use App\Models\User;
use App\Services\Creditos\AuditoriaCredito;
use App\Services\Creditos\Avisos\UrlFrontendTenant;
use Carbon\CarbonImmutable;

/**
 * Link "Mi crédito" del cliente (Fase 5b, 05b-mi-credito-cliente.md): crearlo, regenerarlo y
 * comprobar los últimos 4 caracteres del documento. Un celular que acierta recibe una credencial
 * firmada válida 90 días (no se guarda nada en el servidor); regenerar el link sube la versión y la
 * invalida en todos los celulares.
 */
class EnlaceClienteService
{
    public const DIAS_RECORDADO = 90;
    public const MAXIMO_INTENTOS = 5;
    public const MINUTOS_BLOQUEO = 15;
    private const LARGO_TOKEN = 16;   // base62: ~95 bits, imposible de adivinar

    public function __construct(
        private readonly UrlFrontendTenant $url,
        private readonly AuditoriaCredito $auditoria,
    ) {
    }

    /** Lo que se le pide al cliente: los últimos 4 caracteres de su documento (sin guiones ni espacios). */
    public static function ultimos4(Client $cliente): ?string
    {
        $documento = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $cliente->n_document));

        return strlen($documento) >= 4 ? substr($documento, -4) : null;
    }

    /** El link del cliente; lo crea la primera vez. Sin documento no hay qué pedirle: no se crea. */
    public function paraCliente(Client $cliente, ?User $usuario = null): CreditoEnlaceCliente
    {
        $enlace = CreditoEnlaceCliente::where('cliente_id', $cliente->id)->first();
        if ($enlace !== null) {
            return $enlace;
        }
        $this->exigirDocumento($cliente);
        $token = self::nuevoToken();

        return CreditoEnlaceCliente::create([
            'cliente_id' => $cliente->id,
            'token' => $token,
            'token_hash' => CreditoEnlaceCliente::hash($token),
            'creado_por' => $usuario?->id,
        ]);
    }

    /** Link nuevo: el anterior (y los QR ya impresos) dejan de funcionar. */
    public function regenerar(Client $cliente, User $usuario): CreditoEnlaceCliente
    {
        $this->exigirDocumento($cliente);
        $enlace = CreditoEnlaceCliente::where('cliente_id', $cliente->id)->first();
        if ($enlace === null) {
            return $this->paraCliente($cliente, $usuario);
        }
        $token = self::nuevoToken();
        $enlace->update([
            'token' => $token,
            'token_hash' => CreditoEnlaceCliente::hash($token),
            'version' => $enlace->version + 1,
            'intentos_fallidos' => 0,
            'bloqueado_hasta' => null,
            'creado_por' => $usuario->id,
            'regenerado_en' => now(),
        ]);
        $this->auditoria->registrar('mi_credito.regenerar', $enlace, null, null, ['version' => $enlace->version], null, $usuario);

        return $enlace;
    }

    public function urlDe(CreditoEnlaceCliente $enlace): string
    {
        return $this->url->de('c/' . $enlace->token);
    }

    public function buscar(string $token): ?CreditoEnlaceCliente
    {
        return CreditoEnlaceCliente::with('cliente')->where('token_hash', CreditoEnlaceCliente::hash($token))->first();
    }

    /**
     * Comprueba los 4 caracteres. Correcto → credencial del celular. 5 fallos seguidos bloquean el
     * link 15 minutos (un acierto no lo desbloquea antes).
     */
    public function verificar(CreditoEnlaceCliente $enlace, string $ingresado): ResultadoVerificacion
    {
        if ($enlace->bloqueado()) {
            return ResultadoVerificacion::bloqueado($enlace->bloqueado_hasta);
        }
        $esperado = $enlace->cliente ? self::ultimos4($enlace->cliente) : null;
        $ingresado = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $ingresado));

        if ($esperado !== null && strlen($ingresado) === 4 && hash_equals($esperado, $ingresado)) {
            $enlace->update(['intentos_fallidos' => 0]);
            $vence = CarbonImmutable::now()->addDays(self::DIAS_RECORDADO);

            return ResultadoVerificacion::correcto($this->credencial($enlace, $vence), $vence);
        }

        $intentos = $enlace->intentos_fallidos + 1;
        if ($intentos >= self::MAXIMO_INTENTOS) {
            $enlace->update(['intentos_fallidos' => 0, 'bloqueado_hasta' => now()->addMinutes(self::MINUTOS_BLOQUEO)]);
            $this->auditoria->registrar('mi_credito.bloqueo', $enlace, null, null, ['minutos' => self::MINUTOS_BLOQUEO], 'Intentos fallidos con el documento', null);

            return ResultadoVerificacion::bloqueado($enlace->bloqueado_hasta);
        }
        $enlace->update(['intentos_fallidos' => $intentos]);

        return ResultadoVerificacion::incorrecto(self::MAXIMO_INTENTOS - $intentos);
    }

    /** ¿La credencial que trae el celular es de este link, de su versión actual y sigue vigente? */
    public function credencialValida(CreditoEnlaceCliente $enlace, ?string $credencial): bool
    {
        if (! $credencial || ! str_contains($credencial, '.')) {
            return false;
        }
        [$datos, $firma] = explode('.', $credencial, 2);
        if (! hash_equals($this->firmar($datos), $firma)) {
            return false;
        }
        $c = json_decode((string) base64_decode(strtr($datos, '-_', '+/'), true), true);

        return is_array($c)
            && ($c['e'] ?? null) === $enlace->id
            && ($c['v'] ?? null) === $enlace->version
            && is_int($c['x'] ?? null) && $c['x'] > CarbonImmutable::now()->getTimestamp();
    }

    public function registrarAcceso(CreditoEnlaceCliente $enlace): void
    {
        $enlace->increment('accesos', 1, ['ultimo_acceso_en' => now()]);
    }

    private function credencial(CreditoEnlaceCliente $enlace, CarbonImmutable $vence): string
    {
        $datos = rtrim(strtr(base64_encode((string) json_encode(['e' => $enlace->id, 'v' => $enlace->version, 'x' => $vence->getTimestamp()])), '+/', '-_'), '=');

        return $datos . '.' . $this->firmar($datos);
    }

    /** Firma ligada al tenant (la base es por tenant, pero el id del link podría repetirse entre tenants). */
    private function firmar(string $datos): string
    {
        return hash_hmac('sha256', 'mi-credito|' . (tenant('id') ?? '') . '|' . $datos, (string) config('app.key'));
    }

    private function exigirDocumento(Client $cliente): void
    {
        if (self::ultimos4($cliente) === null) {
            throw new SinDocumentoException('Completa el documento del cliente: "Mi crédito" pide sus últimos 4 dígitos para protegerlo.');
        }
    }

    private static function nuevoToken(): string
    {
        // Sin caracteres que se confunden al tipear (0/O, 1/l/I): el link también va escrito en papel.
        $alfabeto = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $token = '';
        for ($i = 0; $i < self::LARGO_TOKEN; $i++) {
            $token .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $token;
    }
}
