<?php

namespace Database\Factories\Credit;

use App\Models\Client\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Credit\PaymentReceipt>
 */
class PaymentReceiptFactory extends Factory
{
    protected $model = \App\Models\Credit\PaymentReceipt::class;

    public function definition(): array
    {
        return [
            'numero_recibo' => 'REC-' . str_pad((string) fake()->unique()->numberBetween(1, 99999999), 8, '0', STR_PAD_LEFT),
            'client_id' => Client::factory(),
            'fecha_pago' => now()->toDateString(),
            'medio_pago' => fake()->randomElement(['EFECTIVO', 'TRANSFERENCIA', 'YAPE', 'PLIN']),
            'monto_total' => fake()->randomFloat(2, 50, 1000),
            'monto_no_aplicado' => 0,
            'registrado_por' => User::factory(),
            'estado' => 'activo',
        ];
    }

    public function anulado(string $motivo = 'Error de caja'): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'anulado',
            'motivo_anulacion' => $motivo,
            'anulado_por' => User::factory(),
            'anulado_en' => now(),
        ]);
    }

    // Sobrepago — deja excedente sin aplicar (§3.7, pasa a saldo a favor del cliente).
    public function conExcedente(float $excedente = 20.00): static
    {
        return $this->state(fn (array $attributes) => [
            'monto_no_aplicado' => $excedente,
        ]);
    }
}
