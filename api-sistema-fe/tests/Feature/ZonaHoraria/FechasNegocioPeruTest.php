<?php

declare(strict_types=1);

namespace Tests\Feature\ZonaHoraria;

use App\Http\Controllers\Cash\CashSessionController;
use App\Http\Controllers\Sale\SaleController;
use App\Models\Credit\Installment;
use App\Models\Sale\Sale;
use App\Services\CreditSummaryCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * F0 de la homogenización de fechas (08-oct-2026). La app corre en UTC: de 19:00 a 24:00 de
 * Perú, now()/today() ya están en el día siguiente. Datos reales encontrados: agencia-demo F001-2
 * creada el 24-sep a las 19:30 con fecha de emisión impresa 25-sep (umbo y sandbox, igual).
 */
final class FechasNegocioPeruTest extends CreditosTestCase
{
    public function test_formulario_de_venta_propone_la_fecha_de_peru_de_noche(): void
    {
        $this->hoy('2026-10-08', '20:30:00');   // 01:30 UTC del 09-oct
        date_default_timezone_set('UTC');

        $datos = app(SaleController::class)->config()->getData(true);

        $this->assertSame('2026-10-08', $datos['today']);
    }

    public function test_historial_de_caja_filtra_opened_at_utc_por_dia_de_peru(): void
    {
        $usuario = $this->usuario(['cash.view_all']);
        $sesion = $this->abrirCaja($usuario);
        // Abierta el 08-oct a las 19:39 de Perú = 09-oct 00:39 UTC (así se guarda opened_at).
        DB::table('cash_sessions')->where('id', $sesion->id)->update(['opened_at' => '2026-10-09 00:39:27']);
        $this->actingAs($usuario, 'api');

        $ids = fn (string $dia): array => collect(
            app(CashSessionController::class)->index(new Request(['date_from' => $dia, 'date_to' => $dia]))->getData(true)['sessions']
        )->pluck('id')->all();

        $this->assertContains($sesion->id, $ids('2026-10-08'));
        $this->assertNotContains($sesion->id, $ids('2026-10-09'));
    }

    public function test_cuota_que_vence_hoy_no_figura_vencida_de_noche(): void
    {
        $this->hoy('2026-10-08', '20:30:00');
        date_default_timezone_set('UTC');
        $venta = Sale::factory()->cuotasFijas()->create(['type_payment' => 2, 'saldo_pendiente' => 100]);
        Installment::factory()->for($venta, 'sale')->create([
            'numero_cuota' => 1,
            'monto_programado' => 100.00,
            'fecha_vencimiento' => '2026-10-08',
            'estado' => 'pendiente',
        ]);

        $resumen = app(CreditSummaryCalculator::class)->resumenVenta($venta->fresh());

        $this->assertSame(0, $resumen['cuotas_vencidas']);
        $this->assertSame('2026-10-08', $resumen['proxima_cuota_vencimiento']);
    }
}
