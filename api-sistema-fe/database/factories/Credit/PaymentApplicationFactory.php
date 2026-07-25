<?php

namespace Database\Factories\Credit;

use App\Models\Client\Client;
use App\Models\Credit\Installment;
use App\Models\Credit\PaymentReceipt;
use App\Models\Sale\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Credit\PaymentApplication>
 *
 * Nota de uso: los defaults de payment_receipt_id/sale_id crean cada uno su
 * propio Client/Sale independiente — para un escenario realista (el pago
 * aplicado a una venta del MISMO cliente que hizo el recibo), usar
 * deMismoCliente() en vez de un ->for() manual (ojo: encadenar ->for($sale)
 * DESPUÉS de deMismoCliente() no reemplaza la venta que arma por dentro —
 * Factory::for() acumula relaciones, no las pisa — por eso $sale/$receipt
 * se pasan como parámetro, no se encadenan). Ejemplos:
 *   PaymentApplication::factory()->deMismoCliente()->create();
 *
 *   // Reusando ventas ya armadas del escenario FIFO (§3.4) — evita crear
 *   // una venta de más:
 *   $cliente = Client::factory()->create();
 *   $ventaVieja = Sale::factory()->cuotasFijas()->for($cliente)->create(['date' => '2026-01-10']);
 *   PaymentApplication::factory()->deMismoCliente(sale: $ventaVieja)->create();
 *
 *   // Pago general (§3.2): 1 recibo, N aplicaciones sobre distintas ventas
 *   // del MISMO cliente:
 *   $receipt = PaymentReceipt::factory()->for($cliente)->create();
 *   PaymentApplication::factory()->deMismoCliente(sale: $ventaVieja, receipt: $receipt)->create();
 */
class PaymentApplicationFactory extends Factory
{
    protected $model = \App\Models\Credit\PaymentApplication::class;

    public function definition(): array
    {
        return [
            'payment_receipt_id' => PaymentReceipt::factory(),
            'sale_id' => Sale::factory()->credito(),
            'monto_aplicado' => fake()->randomFloat(2, 50, 500),
            'monto_mora_cobrado' => 0,
            'orden_aplicacion' => 1,
            'estado' => 'activo',
        ];
    }

    // Aplicación contra una cuota puntual (venta cuotas_fijas), no contra el
    // saldo general de la venta.
    public function paraCuota(): static
    {
        return $this->state(fn (array $attributes) => [
            'installment_id' => Installment::factory(),
        ]);
    }

    public function conMoraCobrada(float $monto = 5.00): static
    {
        return $this->state(fn (array $attributes) => [
            'monto_mora_cobrado' => $monto,
        ]);
    }

    // Revertida por error de caja (§3.6) o liquidada por devolución (§3.12,
    // ahí además se debe setear refund_id explícito).
    public function anulada(): static
    {
        return $this->state(fn (array $attributes) => ['estado' => 'anulada']);
    }

    // Movida a otra venta por reemplazo de comprobante (§3.13) — el caller
    // debe setear origen_application_id explícito apuntando a la fila vieja.
    public function trasladada(): static
    {
        return $this->state(fn (array $attributes) => ['estado' => 'trasladada']);
    }

    // Arma sale_id y payment_receipt_id sobre el MISMO cliente en vez de
    // los defaults independientes de definition(). $sale/$receipt son
    // opcionales: si ya los armaste (ej. una venta del escenario FIFO, o un
    // recibo que se va a repartir entre varias ventas), pasalos acá en vez
    // de encadenar ->for() después — ver nota de clase.
    public function deMismoCliente(?Client $cliente = null, ?Sale $sale = null, ?PaymentReceipt $receipt = null): static
    {
        $cliente ??= $sale?->client ?? $receipt?->client ?? Client::factory()->create();

        $factory = $receipt
            ? $this->for($receipt, 'paymentReceipt')
            : $this->for(PaymentReceipt::factory()->for($cliente), 'paymentReceipt');

        return $sale
            ? $factory->for($sale)
            : $factory->for(Sale::factory()->credito()->for($cliente), 'sale');
    }
}
