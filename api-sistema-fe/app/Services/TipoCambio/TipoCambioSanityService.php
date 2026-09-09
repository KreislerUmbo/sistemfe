<?php

namespace App\Services\TipoCambio;

// Rango de sanidad para un tipo de cambio USD/PEN. Punto 4 de
// plan-fix-moneda-cotizador.md, nunca implementado — se trae acá como
// prerequisito del módulo de Tipo de Cambio SUNAT porque ambos necesitan
// el mismo criterio de "¿este valor es razonable?" (resolverTipoCambio()
// de AlternativaController y TipoCambioAgenciaController::store() por un
// lado, la sincronización diaria de SUNAT/SBS por el otro). Hallazgo real
// que motivó esto: tipo_cambio_agencia.valor = 1.0000 registrado 4 veces
// sin que nada lo rechazara.
class TipoCambioSanityService
{
    public const RANGO_MINIMO = 2.0;
    public const RANGO_MAXIMO = 6.0;

    public function esRazonable(float $valor): bool
    {
        return $valor >= self::RANGO_MINIMO && $valor <= self::RANGO_MAXIMO;
    }
}
