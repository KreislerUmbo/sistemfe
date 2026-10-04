<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\TipoCorrelativo;
use App\Models\Creditos\CreditoCorrelativo;
use Illuminate\Support\Facades\DB;

/**
 * Correlativos de numero_credito y numero_recibo (decisión Fase 2). Mismo patrón que
 * SerieComprobanteService / CodigoGeneradorService: fila semilla creada por la
 * migración + lockForUpdate() + incremento atómico; nunca MAX() sobre la tabla de
 * negocio (concurrencia). Si el llamador ya está en una transacción, esta queda anidada
 * (savepoint) y el lock dura hasta que el llamador confirme.
 */
class CorrelativoCreditoService
{
    private const DIGITOS = 8;

    /** Reserva y devuelve el siguiente número, ej. CR-00000001. */
    public function siguiente(TipoCorrelativo $tipo): string
    {
        return DB::transaction(function () use ($tipo): string {
            $fila = CreditoCorrelativo::where('tipo', $tipo)->lockForUpdate()->firstOrFail();
            $fila->increment('ultimo_numero');

            return sprintf('%s-%0' . self::DIGITOS . 'd', $fila->prefijo, $fila->ultimo_numero);
        });
    }
}
