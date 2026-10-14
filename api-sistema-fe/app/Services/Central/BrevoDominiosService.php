<?php

namespace App\Services\Central;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Alta y verificación del dominio de correo de una agencia en Brevo, por API
// (plan-modulo-envio-correo-cotizacion.md §2.1, §3.1). Solo desde el entorno
// central (Superadmin): la API key nunca llega al tenant ni al frontend.
//
// Endpoints usados (API v3):
//   POST /senders/domains                         registra el dominio y devuelve los DNS
//   GET  /senders/domains/{dominio}               estado y registros DNS
//   PUT  /senders/domains/{dominio}/authenticate  pide a Brevo comprobar los DNS
//   DELETE /senders/domains/{dominio}             lo quita de la cuenta
//
// La forma exacta de `dns_records` se normaliza en registrosDns() de manera
// defensiva: se guarda la respuesta cruda y se muestra lo que tenga tipo,
// nombre y valor. Confirmar contra la cuenta real en la etapa 0 del plan.
class BrevoDominiosService
{
    public function configurado(): bool
    {
        return (bool) config('services.brevo.api_key');
    }

    /** @return array{dns_records: array, crudo: array} */
    public function registrar(string $dominio): array
    {
        $respuesta = $this->cliente()->post('/senders/domains', ['name' => $dominio]);

        // Si el dominio ya estaba en la cuenta, Brevo responde error: se lee
        // su estado actual en vez de fallar.
        if ($respuesta->failed() && $respuesta->status() === 400 && str_contains(strtolower((string) $respuesta->body()), 'already')) {
            return $this->consultar($dominio);
        }

        $this->lanzarSiFalla($respuesta, 'registrar el dominio');
        $datos = $respuesta->json() ?? [];

        return ['dns_records' => $datos['dns_records'] ?? [], 'crudo' => $datos];
    }

    /** @return array{dns_records: array, crudo: array, verificado: bool, autenticado: bool} */
    public function consultar(string $dominio): array
    {
        $respuesta = $this->cliente()->get('/senders/domains/' . rawurlencode($dominio));
        $this->lanzarSiFalla($respuesta, 'consultar el dominio');
        $datos = $respuesta->json() ?? [];

        return [
            'dns_records' => $datos['dns_records'] ?? [],
            'crudo' => $datos,
            'verificado' => (bool) ($datos['verified'] ?? false),
            'autenticado' => (bool) ($datos['authenticated'] ?? false),
        ];
    }

    /** @return array{autenticado: bool, dns_records: array, mensaje: ?string} */
    public function autenticar(string $dominio): array
    {
        $respuesta = $this->cliente()->put('/senders/domains/' . rawurlencode($dominio) . '/authenticate');
        $mensaje = $respuesta->json('message');

        // Un 4xx acá suele significar "los DNS todavía no están": no es un
        // error del sistema, se informa y se consulta el estado real.
        if ($respuesta->serverError()) {
            $this->lanzarSiFalla($respuesta, 'verificar el dominio');
        }

        $estado = $this->consultar($dominio);

        return [
            'autenticado' => $estado['autenticado'],
            'dns_records' => $estado['dns_records'],
            'mensaje' => is_string($mensaje) ? $mensaje : null,
        ];
    }

    public function eliminar(string $dominio): void
    {
        $respuesta = $this->cliente()->delete('/senders/domains/' . rawurlencode($dominio));
        if ($respuesta->failed() && $respuesta->status() !== 404) {
            $this->lanzarSiFalla($respuesta, 'eliminar el dominio');
        }
    }

    /**
     * Registros DNS normalizados para mostrarlos con un botón "copiar".
     *
     * @return array<int, array{clave: string, tipo: string, nombre: string, valor: string, estado: ?bool}>
     */
    public static function registrosDns(?array $dnsRecords): array
    {
        $salida = [];
        foreach ($dnsRecords ?? [] as $clave => $registro) {
            if (! is_array($registro)) {
                continue;
            }
            $tipo = $registro['type'] ?? null;
            $valor = $registro['value'] ?? null;
            if (! $tipo || ! $valor) {
                continue;
            }
            $salida[] = [
                'clave' => (string) $clave,
                'tipo' => strtoupper((string) $tipo),
                'nombre' => (string) ($registro['host_name'] ?? $registro['hostname'] ?? $registro['name'] ?? '@'),
                'valor' => (string) $valor,
                'estado' => isset($registro['status']) ? (bool) $registro['status'] : null,
            ];
        }

        return $salida;
    }

    private function cliente(): PendingRequest
    {
        $apiKey = config('services.brevo.api_key');
        if (! $apiKey) {
            throw new RuntimeException('Falta BREVO_API_KEY en el entorno central.');
        }

        return Http::baseUrl((string) config('services.brevo.base_url'))
            ->withHeaders(['api-key' => $apiKey, 'accept' => 'application/json'])
            ->asJson()
            ->timeout(20);
    }

    private function lanzarSiFalla($respuesta, string $accion): void
    {
        if ($respuesta->successful()) {
            return;
        }
        $detalle = $respuesta->json('message') ?? mb_substr((string) $respuesta->body(), 0, 300);

        throw new RuntimeException("Brevo no pudo {$accion} ({$respuesta->status()}): {$detalle}");
    }
}
