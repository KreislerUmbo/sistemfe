<?php

namespace App\Services\TipoCambio;

use App\Models\TipoCambio\TipoCambioSunat;
use Carbon\Carbon;

// Fase 4 del plan de Tipo de Cambio SUNAT. Decisión del usuario
// (09-sep-2026): NO bloquea la emisión de un comprobante en USD si no hay
// tipo de cambio disponible para la fecha — devuelve null y el llamador
// sigue de largo, sin CRUD de carga manual. El snapshot resultante queda
// null en ese caso (ver sales.tipo_cambio_sunat_aplicado/
// notes.tipo_cambio_sunat_aplicado).
class TipoCambioSunatResolver
{
    public function resolverParaFecha(Carbon $fecha): ?TipoCambioSunat
    {
        $fechaStr = $fecha->toDateString();

        // Fecha exacta primero; si SBS no publicó ese día (feriado/fin de
        // semana), el último valor hábil disponible anterior a esa fecha.
        return TipoCambioSunat::where('fecha', '<=', $fechaStr)
            ->orderByDesc('fecha')
            ->first();
    }
}
