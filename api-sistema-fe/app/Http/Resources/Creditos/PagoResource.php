<?php

declare(strict_types=1);

namespace App\Http\Resources\Creditos;

use App\Models\Creditos\CreditoPago;
use App\Models\Creditos\CreditoPagoAplicacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditoPago */
class PagoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'credito_id' => $this->credito_id,
            'numero_recibo' => $this->numero_recibo,
            'monto_recibido' => $this->monto_recibido,
            'monto_aplicado' => $this->monto_aplicado,
            'monto_excedente' => $this->monto_excedente,
            'destino_excedente' => $this->destino_excedente?->value,
            'fecha_pago' => $this->fecha_pago->format('Y-m-d H:i:s'),
            'origen' => $this->origen->value,
            'es_cierre' => $this->es_cierre,
            'pagado_por_cliente_id' => $this->pagado_por_cliente_id,
            'payment_method_id' => $this->payment_method_id,
            'referencia' => $this->referencia,
            'observaciones' => $this->observaciones,
            'estado' => $this->estado->value,
            'motivo_anulacion' => $this->motivo_anulacion,
            'registrado_por' => $this->registrado_por,
            'aplicaciones' => $this->whenLoaded('aplicaciones', fn () => $this->aplicaciones
                ->where('vigente', true)
                ->map(fn (CreditoPagoAplicacion $a): array => [
                    'numero_cuota' => $a->cuota?->numero_cuota,
                    'concepto' => $a->concepto->value,
                    'monto' => $a->monto,
                ])->values()),
        ];
    }
}
