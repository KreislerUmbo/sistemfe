<?php

namespace Database\Factories\Credit;

use App\Models\Sale\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Credit\Installment>
 */
class InstallmentFactory extends Factory
{
    protected $model = \App\Models\Credit\Installment::class;

    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory()->cuotasFijas(),
            'numero_cuota' => 1,
            'monto_programado' => fake()->randomFloat(2, 50, 500),
            'fecha_vencimiento' => now()->addMonth()->toDateString(),
            'estado' => 'pendiente',
        ];
    }

    // Cuota vencida sin pago — para probar el cálculo on-the-fly de mora (§3.8).
    public function vencida(int $diasAtraso = 15): static
    {
        return $this->state(fn (array $attributes) => [
            'fecha_vencimiento' => now()->subDays($diasAtraso)->toDateString(),
            'estado' => 'vencida',
        ]);
    }

    public function pagada(): static
    {
        return $this->state(fn (array $attributes) => ['estado' => 'pagada']);
    }

    public function anulada(string $motivo = 'Renegociación de cronograma'): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'anulada',
            'motivo_anulacion' => $motivo,
            'anulado_por' => User::factory(),
            'anulado_en' => now(),
        ]);
    }
}
