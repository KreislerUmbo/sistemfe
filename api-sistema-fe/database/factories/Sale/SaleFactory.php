<?php

namespace Database\Factories\Sale;

use App\Models\Client\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sale\Sale>
 *
 * Base: venta contado normal, ya pagada — el caso más común en dev hoy.
 * Estados pensados para los escenarios de prueba del módulo Amortizaciones
 * (plan-modulo-amortizaciones.md §3.4 FIFO y §3.11 cartera):
 *   - credito()/cuotasFijas()/libre(): ventas a crédito formal
 *   - conMora() / libreVencida(): mora activa, con fecha ya vencida
 *   - contadoConDeuda(): el caso real encontrado en dev (Fase 1, §9) — deuda
 *     vía debt/paid_out en una venta condicion_pago='contado', sin cronograma
 */
class SaleFactory extends Factory
{
    protected $model = \App\Models\Sale\Sale::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 2000);
        $igv = round($subtotal * 0.18, 2);
        $total = round($subtotal + $igv, 2);
        $correlativo = fake()->unique()->numberBetween(1, 9999999);

        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'serie' => 'F001',
            'correlativo' => $correlativo,
            'n_operacion' => sprintf('F001-%08d', $correlativo),
            'n_transaction' => str_pad((string) $correlativo, 8, '0', STR_PAD_LEFT),
            'date' => now()->toDateString(),
            'currency' => 'PEN',
            'type_client' => 1,
            'subtotal' => $subtotal,
            'igv' => $igv,
            'total' => $total,
            'type_payment' => 1, // contado
            'state_sale' => 1,   // venta (no cotización)
            'state_payment' => 3, // pagado completo
            'debt' => 0,
            'paid_out' => $total,
            'retencion_igv' => 0,

            // ── Módulo Amortizaciones — default: sin crédito, sin deuda ──
            'condicion_pago' => 'contado',
            'aplica_mora' => false,
            'saldo_pendiente' => 0,
        ];
    }

    // ── Venta a crédito, sin definir todavía cuotas_fijas/libre ─────────
    public function credito(): static
    {
        return $this->state(fn (array $attributes) => [
            'condicion_pago' => 'credito',
            'type_payment' => 1, // sin cambios: sync con type_payment queda pendiente (§9, Fase 3)
            'state_payment' => 1, // pendiente
            'debt' => $attributes['total'] ?? 0,
            'paid_out' => 0,
            'saldo_pendiente' => $attributes['total'] ?? 0,
        ]);
    }

    // Crédito con cronograma de cuotas (installments) — usar junto con
    // Installment::factory() para generar las filas, ej.:
    //   Sale::factory()->cuotasFijas()->has(Installment::factory()->count(3), 'installments')
    public function cuotasFijas(): static
    {
        return $this->credito()->state(fn (array $attributes) => [
            'credit_type' => 'cuotas_fijas',
        ]);
    }

    // Crédito sin cronograma — saldo único contra la venta completa.
    public function libre(): static
    {
        return $this->credito()->state(fn (array $attributes) => [
            'credit_type' => 'libre',
        ]);
    }

    // ── Mora activa — combinar con credito()/cuotasFijas()/libre() ──────
    public function conMora(string $tipoMora = 'porcentaje_diario', float $tasaMora = 1.0000): static
    {
        return $this->state(fn (array $attributes) => [
            'aplica_mora' => true,
            'tipo_mora' => $tipoMora,
            'tasa_mora' => $tasaMora,
        ]);
    }

    // Venta libre con mora activa y fecha_limite_pago ya vencida — para
    // probar el cálculo on-the-fly de mora (§3.8) sin depender de installments.
    public function libreVencida(int $diasAtraso = 10): static
    {
        return $this->libre()->conMora()->state(fn (array $attributes) => [
            'fecha_limite_pago' => now()->subDays($diasAtraso)->toDateString(),
        ]);
    }

    // ── Caso real encontrado en dev (Fase 1, §9): venta 'contado' con
    // deuda pendiente vía debt/paid_out, sin condicion_pago='credito' ni
    // cronograma — igual debe ser cobrable por saldo_pendiente > 0. ──────
    public function contadoConDeuda(): static
    {
        return $this->state(function (array $attributes) {
            $total = $attributes['total'] ?? 100;
            $debt = round($total * 0.2, 2);

            return [
                'condicion_pago' => 'contado',
                'state_payment' => 2, // pago parcial
                'debt' => $debt,
                'paid_out' => round($total - $debt, 2),
                'saldo_pendiente' => $debt,
            ];
        });
    }
}
