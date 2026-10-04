<?php

declare(strict_types=1);

namespace App\Http\Resources\Creditos;

use App\Models\Creditos\CreditoCuota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditoCuota */
class CuotaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_cuota' => $this->numero_cuota,
            'fecha_inicio_periodo' => $this->fecha_inicio_periodo->format('Y-m-d'),
            'fecha_vencimiento' => $this->fecha_vencimiento->format('Y-m-d'),
            'fecha_vencimiento_original' => $this->fecha_vencimiento_original->format('Y-m-d'),
            'monto_capital' => $this->monto_capital,
            'monto_interes' => $this->monto_interes,
            'monto_total' => $this->monto_total,
            'cargo_monto' => $this->cargo_monto,
            'capital_pagado' => $this->capital_pagado,
            'interes_pagado' => $this->interes_pagado,
            'cargo_pagado' => $this->cargo_pagado,
            'mora_pagada' => $this->mora_pagada,
            'mora_condonada' => $this->mora_condonada,
            'mora_congelada' => $this->mora_congelada,
            'interes_condonado' => $this->interes_condonado,
            'estado' => $this->estado->value,
            'fecha_pago' => $this->fecha_pago?->format('Y-m-d'),
            'dias_atraso_al_pagar' => $this->dias_atraso_al_pagar,
        ];
    }
}
