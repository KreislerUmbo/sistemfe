<?php

namespace App\Services\TipoCambio;

use App\Models\TipoCambio\TipoCambioSunat;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 3 del plan de Tipo de Cambio SUNAT. Decolecta como fuente primaria
// (requiere token Bearer — confirmado en vivo, sin token responde 401 pese
// a que su propia documentación lo sugiere "opcional"), e-api.net.pe como
// fallback sin autenticación. Ambos republican el mismo dato SBS.
//
// Si ambas fuentes fallan, o el valor que devuelven cae fuera del rango de
// sanidad (TipoCambioSanityService), NO se escribe nada — se deja el
// último valor vigente intacto y se deja constancia con Log::critical
// (§6 pregunta 6 del plan: sin canal de alerta dedicado por ahora).
class TipoCambioSunatSyncService
{
    public function __construct(private TipoCambioSanityService $sanity)
    {
    }

    public function sincronizar(?Carbon $fecha = null): ?TipoCambioSunat
    {
        $fecha = $fecha ?? now();
        $fechaStr = $fecha->toDateString();

        $datos = $this->consultarDecolecta($fechaStr) ?? $this->consultarEApi($fechaStr);

        if ($datos === null) {
            Log::critical("TipoCambioSunatSyncService: no se pudo obtener el tipo de cambio SUNAT para {$fechaStr} — Decolecta y e-api.net.pe fallaron. No se sobrescribe el último valor vigente.");

            return null;
        }

        if (! $this->sanity->esRazonable($datos['compra']) || ! $this->sanity->esRazonable($datos['venta'])) {
            Log::critical("TipoCambioSunatSyncService: valor fuera de rango de sanidad para {$fechaStr} (fuente: {$datos['fuente']}) — compra={$datos['compra']} venta={$datos['venta']}. No se guarda.");

            return null;
        }

        // updateOrCreate por 'fecha': idempotente — permite re-ejecutar el
        // comando manualmente para backfill de un día atrasado sin duplicar.
        return TipoCambioSunat::updateOrCreate(
            ['fecha' => $fechaStr],
            [
                'compra' => $datos['compra'],
                'venta' => $datos['venta'],
                'fuente' => $datos['fuente'],
                'consultado_en' => now(),
            ]
        );
    }

    private function consultarDecolecta(string $fecha): ?array
    {
        $token = config('services.decolecta.token');
        if (empty($token)) {
            // Sin token no tiene sentido intentar — el endpoint responde 401
            // siempre. Se pasa directo al fallback sin gastar la llamada.
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->retry(2, 500)
                ->get(config('services.decolecta.url') . '/v1/tipo-cambio/sunat', ['date' => $fecha]);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();
            if (! isset($body['buy_price'], $body['sell_price'])) {
                return null;
            }

            return [
                'compra' => (float) $body['buy_price'],
                'venta' => (float) $body['sell_price'],
                'fuente' => 'decolecta',
            ];
        } catch (\Throwable $e) {
            // No re-lanza — el llamador ya trata "null" como "pasá al
            // fallback"— pero sí deja rastro real del motivo (antes se
            // descartaba en silencio; si algún día falla por un bug propio
            // y no por una caída real del proveedor, el Log::critical final
            // no daba ninguna pista de la causa).
            Log::warning("TipoCambioSunatSyncService: fallo consultando Decolecta para {$fecha}: {$e->getMessage()}");

            return null;
        }
    }

    private function consultarEApi(string $fecha): ?array
    {
        try {
            $response = Http::timeout(10)
                ->retry(2, 500)
                ->get(config('services.e_api.url') . "/tipo-cambio/{$fecha}.json");

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();
            if (! isset($body['compra'], $body['venta'])) {
                return null;
            }

            return [
                'compra' => (float) $body['compra'],
                'venta' => (float) $body['venta'],
                'fuente' => 'e-api',
            ];
        } catch (\Throwable $e) {
            Log::warning("TipoCambioSunatSyncService: fallo consultando e-api.net.pe para {$fecha}: {$e->getMessage()}");

            return null;
        }
    }
}
