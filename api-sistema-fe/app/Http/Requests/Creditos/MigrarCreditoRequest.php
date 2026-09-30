<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Rules\Creditos\MontoSoles;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\PagoHistorico;
use App\Services\Creditos\Motor\Fecha;

/** Registrar un crédito existente (00 1.12): pagos históricos en modo rápido o detallado. */
class MigrarCreditoRequest extends CreditoDatosRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'modo_pagos' => ['required', 'in:rapido,detallado'],
            'cuotas_pagadas' => ['required_if:modo_pagos,rapido', 'nullable', 'integer', 'min:0'],
            'pagos' => ['required_if:modo_pagos,detallado', 'array'],
            'pagos.*.fecha' => ['required', 'date_format:Y-m-d'],
            'pagos.*.monto' => ['required', new MontoSoles()],
            'condonar_mora' => ['nullable', 'boolean'],
            ...ClaveIdempotencia::reglas(),
        ];
    }

    public function cuotasATiempo(): ?int
    {
        return $this->input('modo_pagos') === 'rapido' ? (int) $this->input('cuotas_pagadas', 0) : null;
    }

    /** @return list<PagoHistorico> */
    public function pagosDetallados(): array
    {
        if ($this->input('modo_pagos') !== 'detallado') {
            return [];
        }

        return array_values(array_map(
            static fn (array $p): PagoHistorico => new PagoHistorico(Fecha::desdeTexto($p['fecha']), Dinero::aCentavos($p['monto'])),
            $this->input('pagos', []),
        ));
    }
}
