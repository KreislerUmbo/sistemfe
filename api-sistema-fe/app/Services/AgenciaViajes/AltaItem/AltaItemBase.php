<?php

namespace App\Services\AgenciaViajes\AltaItem;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Services\AgenciaViajes\PriceEngineService;
use Illuminate\Support\Facades\Validator;

// T-095 — lo común a las altas de ítem por origen_tipo. Cada servicio hijo valida sus datos, calcula
// costo/venta y crea el ítem; quien lo llama (AlternativaItemController::store()) autoriza antes.
abstract class AltaItemBase
{
    public function __construct(protected PriceEngineService $priceEngine)
    {
    }

    abstract public function crear(Alternativa $alternativa, array $datos): AlternativaItem;

    /** Valida $datos con $reglas; si falla lanza AltaItemException con el primer error. */
    public function validarReglas(array $datos, array $reglas): array
    {
        $validator = Validator::make($datos, $reglas);

        if ($validator->fails()) {
            throw new AltaItemException($validator->errors()->first());
        }

        return $validator->validated();
    }

    /** Precio en la moneda de la alternativa, con el tipo de cambio que ella aplicó. */
    protected function convertir(float $monto, string $moneda, Alternativa $alternativa): float
    {
        return $this->priceEngine->convertirMoneda($monto, $moneda, $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado);
    }

    /** Cierra el alta: recalcula el total de la alternativa con el ítem nuevo. */
    protected function cerrar(Alternativa $alternativa): void
    {
        $alternativa->recalcularTotal();
    }
}
