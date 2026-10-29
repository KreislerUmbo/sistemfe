<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Models\Advance\Advance;
use App\Models\Cash\CashMovement;
use App\Models\Sale\Sale;
use App\Services\Cash\CompletarMonedaCajaService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * T-009 parte 2 (CA4): caja:completar-moneda pasa a su moneda real los movimientos viejos que
 * quedaron en 'PEN' por defecto. La base de pruebas tiene otros datos: se filtra por ids propios.
 */
final class T009CompletarMonedaCajaTest extends CreditosTestCase
{
    /** Inserta un movimiento "viejo": sin moneda (queda en PEN por defecto). */
    private function viejo(int $sesionId, array $extra): int
    {
        return DB::table('cash_movements')->insertGetId(array_merge([
            'cash_session_id' => $sesionId, 'type' => 'sale_payment', 'payment_method_id' => $this->efectivo->id,
            'direction' => 'in', 'amount' => 100, 'status' => 'confirmed', 'created_by' => DB::table('cash_sessions')->where('id', $sesionId)->value('opened_by'),
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function venta(string $moneda): Sale
    {
        return Sale::factory()->create(['currency' => $moneda, 'total' => 100]);
    }

    private function correr(array $ids, bool $simular = false): array
    {
        $r = app(CompletarMonedaCajaService::class)->completar($simular);

        return [
            'cambios' => array_values(array_filter($r['cambios'], fn ($c) => in_array($c['movimiento_id'], $ids, true))),
            'revisar' => array_values(array_filter($r['revisar'], fn ($c) => in_array($c['movimiento_id'], $ids, true))),
        ];
    }

    public function test_pasa_a_usd_lo_de_una_venta_en_dolares_y_su_correccion_y_deja_registro(): void
    {
        $sesion = $this->abrirCaja($this->usuario(['cash.open_session']));
        $usd = $this->venta('USD');
        $pen = $this->venta('PEN');
        $m1 = $this->viejo($sesion->id, ['reference_type' => 'sale', 'reference_id' => $usd->id]);
        $m2 = $this->viejo($sesion->id, ['reference_type' => 'sale', 'reference_id' => $pen->id]);
        $corr = $this->viejo($sesion->id, [
            'type' => 'correction', 'direction' => 'out', 'reference_type' => 'cash_movement',
            'reference_id' => $m1, 'corrected_movement_id' => $m1,
        ]);

        $r = $this->correr([$m1, $m2, $corr]);

        $this->assertEqualsCanonicalizing([$m1, $corr], array_column($r['cambios'], 'movimiento_id'));
        $this->assertSame('USD', DB::table('cash_movements')->where('id', $m1)->value('currency'));
        $this->assertSame('USD', DB::table('cash_movements')->where('id', $corr)->value('currency'));
        $this->assertSame('PEN', DB::table('cash_movements')->where('id', $m2)->value('currency'));
        $this->assertSame(2, DB::table('ajustes_moneda_caja')->whereIn('cash_movement_id', [$m1, $corr])->count());
        $this->assertSame('100.00', number_format((float) DB::table('cash_movements')->where('id', $m1)->value('amount'), 2, '.', ''));
    }

    public function test_un_adelanto_en_dolares_pasa_a_usd(): void
    {
        $sesion = $this->abrirCaja($this->usuario(['cash.open_session']));
        $cliente = $this->cliente();
        $venta = Sale::factory()->create(['type' => 'advance', 'client_id' => $cliente->id, 'currency' => 'USD', 'total' => 100]);
        $adelanto = Advance::create(['client_id' => $cliente->id, 'sale_id' => $venta->id, 'amount' => 100, 'currency' => 'USD', 'payment_method' => 'EFECTIVO']);
        $m = $this->viejo($sesion->id, ['type' => 'advance_received', 'reference_type' => 'advance', 'reference_id' => $adelanto->id]);

        $this->correr([$m]);

        $this->assertSame('USD', DB::table('cash_movements')->where('id', $m)->value('currency'));
    }

    public function test_un_recibo_con_ventas_mezcladas_va_a_revisar_sin_tocarse(): void
    {
        $sesion = $this->abrirCaja($this->usuario(['cash.open_session']));
        $cliente = $this->cliente();
        $recibo = DB::table('payment_receipts')->insertGetId([
            'numero_recibo' => 'T009-' . uniqid(), 'client_id' => $cliente->id, 'fecha_pago' => '2026-01-01',
            'medio_pago' => 'EFECTIVO', 'monto_total' => 100, 'monto_no_aplicado' => 0, 'registrado_por' => $sesion->opened_by,
            'estado' => 'activo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['PEN', 'USD'] as $i => $moneda) {
            DB::table('payment_applications')->insert([
                'payment_receipt_id' => $recibo, 'sale_id' => $this->venta($moneda)->id, 'monto_aplicado' => 50,
                'monto_mora_cobrado' => 0, 'orden_aplicacion' => $i + 1, 'estado' => 'activo',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $m = $this->viejo($sesion->id, ['type' => 'installment_payment', 'reference_type' => 'payment_receipt', 'reference_id' => $recibo]);

        $r = $this->correr([$m]);

        $this->assertSame([], $r['cambios']);
        $this->assertCount(1, $r['revisar']);
        $this->assertSame('PEN', DB::table('cash_movements')->where('id', $m)->value('currency'));
    }

    public function test_simular_no_escribe_y_correrlo_dos_veces_no_cambia_nada(): void
    {
        $sesion = $this->abrirCaja($this->usuario(['cash.open_session']));
        $m = $this->viejo($sesion->id, ['reference_type' => 'sale', 'reference_id' => $this->venta('USD')->id]);

        $simulado = $this->correr([$m], true);
        $this->assertCount(1, $simulado['cambios']);
        $this->assertSame('PEN', DB::table('cash_movements')->where('id', $m)->value('currency'));
        $this->assertSame(0, DB::table('ajustes_moneda_caja')->where('cash_movement_id', $m)->count());

        $this->assertCount(1, $this->correr([$m])['cambios']);
        $this->assertSame([], $this->correr([$m])['cambios']);
        $this->assertSame(1, DB::table('ajustes_moneda_caja')->where('cash_movement_id', $m)->count());
        $this->assertSame('USD', CashMovement::find($m)->currency);
    }
}
