<?php

declare(strict_types=1);

namespace App\Http\Resources\Creditos;

use App\Models\Creditos\CreditoCuota;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\DetalleCredito;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Detalle con la situación calculada a hoy (mora en vivo, exigible). @property DetalleCredito $resource */
class DetalleCreditoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $d = $this->resource;
        $situacion = $d->situacion;

        return [
            'credito' => (new CreditoResource($d->credito->withoutRelations()->setRelation('cliente', $d->credito->cliente)))->toArray($request),
            'resumen' => [
                'saldo_capital' => Dinero::aSoles($d->saldoCapital),
                'saldo_interes' => Dinero::aSoles($d->saldoInteres),
                'mora_pendiente' => Dinero::aSoles($situacion?->moraPendienteTotal() ?? 0),
                'exigible_hoy' => Dinero::aSoles($d->exigible),
                'dias_atraso' => $d->diasAtraso,
            ],
            'cuotas' => $d->credito->cuotasVigentes->map(function (CreditoCuota $c) use ($request, $situacion): array {
                $mora = $situacion?->mora($c->numero_cuota);

                return [
                    ...(new CuotaResource($c))->toArray($request),
                    'mora_pendiente_hoy' => Dinero::aSoles($mora?->moraPendiente ?? 0),
                    'dias_atraso_hoy' => $mora?->diasAtraso ?? 0,
                    'mora_tope_alcanzado' => $mora?->topeAlcanzado ?? false,
                ];
            })->values(),
        ];
    }
}
