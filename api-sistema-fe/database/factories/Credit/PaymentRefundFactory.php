<?php

namespace Database\Factories\Credit;

use App\Models\Sale\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Credit\PaymentRefund>
 */
class PaymentRefundFactory extends Factory
{
    protected $model = \App\Models\Credit\PaymentRefund::class;

    public function definition(): array
    {
        $pagado = fake()->randomFloat(2, 100, 1000);
        $retenido = round($pagado * 0.1, 2); // gasto operativo retenido, ej. 10%

        return [
            'sale_id' => Sale::factory()->credito(),
            'monto_pagado_total' => $pagado,
            'monto_retenido' => $retenido,
            'motivo_retencion' => 'Gastos operativos de devolución (flete/reembalaje)',
            'monto_devuelto' => round($pagado - $retenido, 2),
            'medio_devolucion' => 'EFECTIVO',
            'fecha_devolucion' => now()->toDateString(),
            'autorizado_por' => User::factory(),
            'estado' => 'pendiente',
        ];
    }

    public function completado(): static
    {
        return $this->state(fn (array $attributes) => ['estado' => 'completado']);
    }
}
