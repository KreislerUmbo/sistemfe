<?php

declare(strict_types=1);

namespace App\Http\Resources\Creditos;

use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\DetalleCredito;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una fila del listado de créditos (04-frontend pantalla 4): datos del crédito + situación a hoy.
 * Borradores y anulados no tienen situación: saldo y próximo pago van en null.
 *
 * @property DetalleCredito $resource
 */
class FilaCreditoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $d = $this->resource;
        $c = $d->credito;

        return [
            'id' => $c->id,
            'numero_credito' => $c->numero_credito,
            'estado' => $c->estado->value,
            'cliente' => [
                'id' => $c->cliente->id,
                'nombre' => $c->cliente->full_name,
                'documento' => $c->cliente->n_document,
                'telefono' => $c->cliente->phone ?? null,
            ],
            'monto_capital' => $c->monto_capital,
            'monto_total' => Dinero::aSoles(Dinero::aCentavos($c->monto_capital) + Dinero::aCentavos($c->interes_total)),
            'frecuencia_unidad' => $c->frecuencia_unidad->value,
            'frecuencia_intervalo' => $c->frecuencia_intervalo,
            'numero_cuotas' => $c->numero_cuotas,
            'fecha_desembolso' => $c->fecha_desembolso?->format('Y-m-d'),
            'origen_registro' => $c->origen_registro->value,
            'situacion' => $d->saldo === null ? null : [
                'saldo_por_pagar' => Dinero::aSoles($d->saldo->porPagar()),
                'exigible_hoy' => Dinero::aSoles($d->exigible),
                'mora_pendiente' => Dinero::aSoles($d->saldo->mora),
                'dias_atraso' => $d->diasAtraso,
                'cuotas_pagadas' => $d->saldo->cuotasPagadas,
                'cuotas_vencidas' => $d->saldo->cuotasVencidas,
                'cuotas_total' => $c->cuotasVigentes->count(),
                'proxima' => FormatoCredito::proxima($d->saldo->proxima),
            ],
        ];
    }
}
