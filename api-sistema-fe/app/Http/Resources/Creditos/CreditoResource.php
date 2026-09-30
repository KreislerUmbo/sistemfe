<?php

declare(strict_types=1);

namespace App\Http\Resources\Creditos;

use App\Models\Creditos\Credito;
use App\Services\Creditos\Dinero;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Credito */
class CreditoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_credito' => $this->numero_credito,
            'estado' => $this->estado->value,
            'cliente' => $this->whenLoaded('cliente', fn () => [
                'id' => $this->cliente->id,
                'nombre' => $this->cliente->full_name,
                'documento' => $this->cliente->n_document,
                'telefono' => $this->cliente->phone ?? null,
            ]),
            'cliente_id' => $this->cliente_id,
            'monto_capital' => $this->monto_capital,
            'tasa_interes' => $this->tasa_interes,
            'unidad_tasa' => $this->unidad_tasa->value,
            'interes_total' => $this->interes_total,
            'monto_total' => Dinero::aSoles(Dinero::aCentavos($this->monto_capital) + Dinero::aCentavos($this->interes_total)),
            'frecuencia_unidad' => $this->frecuencia_unidad->value,
            'frecuencia_intervalo' => $this->frecuencia_intervalo,
            'dias_quincena' => $this->dias_quincena,
            'numero_cuotas' => $this->numero_cuotas,
            'fecha_desembolso' => $this->fecha_desembolso?->format('Y-m-d'),
            'fecha_primer_vencimiento' => $this->fecha_primer_vencimiento?->format('Y-m-d'),
            'dias_no_laborables' => $this->dias_no_laborables,
            'saltar_feriados' => $this->saltar_feriados,
            'regla_no_laborable' => $this->regla_no_laborable->value,
            'mora_cuenta_no_laborables' => $this->mora_cuenta_no_laborables,
            'tasa_interes_minimo' => $this->tasa_interes_minimo,
            'dias_gracia' => $this->dias_gracia,
            'tope_mora_tipo' => $this->tope_mora_tipo->value,
            'tope_mora_valor' => $this->tope_mora_valor,
            'payment_method_id' => $this->payment_method_id,
            'origen_registro' => $this->origen_registro->value,
            'credito_renovado_id' => $this->credito_renovado_id,
            'version_cronograma_actual' => $this->version_cronograma_actual,
            'fecha_castigo' => $this->fecha_castigo?->format('Y-m-d'),
            'motivo_cierre' => $this->motivo_cierre?->value,
            'motivo_anulacion' => $this->motivo_anulacion,
            'cuotas' => CuotaResource::collection($this->whenLoaded('cuotasVigentes')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
