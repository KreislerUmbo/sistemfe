<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Exports\CashMovementsExport;
use App\Http\Controllers\Cash\CashSessionController;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * T-013b (Caja, paso B2): historial, PDF y Excel separan S/ y US$; una sesión cerrada antes de T-013 sigue
 * viéndose y su PDF sale sin error (CA4).
 */
final class T013bReportesPorMonedaTest extends CreditosTestCase
{
    private function mov(CashSession $sesion, string $moneda, float $monto): CashMovement
    {
        return CashMovement::create([
            'cash_session_id' => $sesion->id, 'type' => 'advance_received', 'payment_method_id' => $this->efectivo->id,
            'direction' => 'in', 'amount' => $monto, 'currency' => $moneda, 'status' => 'confirmed',
            'created_by' => $sesion->opened_by,
        ]);
    }

    private function pdf(CashSession $sesion): string
    {
        Company::first() ?? Company::create(['razon_social' => 'Empresa Test SAC', 'razon_social_comercial' => 'Empresa Test', 'n_document' => '20123456789']);
        $respuesta = app(CashSessionController::class)->pdf((string) $sesion->id);

        return $respuesta->getContent();
    }

    public function test_ca4_sesion_cerrada_antes_de_t013_se_ve_y_su_pdf_sale(): void
    {
        $cajero = $this->usuario(['cash.open_session', 'cash.view_all']);
        $sesion = $this->abrirCaja($cajero);
        $this->mov($sesion, 'PEN', 300);
        // Como quedó un cierre anterior: solo columnas en soles, las de dólares sin llenar.
        $sesion->update(['status' => 'closed', 'closed_by' => $cajero->id, 'closed_at' => now(),
            'expected_cash' => 300, 'counted_cash' => 300, 'difference' => 0]);

        $detalle = app(CashSessionController::class)->show((string) $sesion->id)->getData(true)['session'];
        $this->assertEquals(0.0, $detalle['expected_cash_usd_live']);
        $this->assertNull($detalle['difference_usd']);
        $this->assertStringStartsWith('%PDF', $this->pdf($sesion));

        $request = Request::create('/cash/sessions', 'GET');
        $request->setUserResolver(fn () => Auth::guard('api')->user());
        $this->app->instance('request', $request);
        $lista = app(CashSessionController::class)->index($request)->getData(true);
        $this->assertArrayHasKey('difference_usd', $lista['sessions'][0]);
    }

    public function test_pdf_de_sesion_con_dolares_sale(): void
    {
        $cajero = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($cajero);
        $sesion->update(['opening_amount_usd' => 50]);
        $this->mov($sesion, 'USD', 200);
        $this->mov($sesion, 'PEN', 100);

        $this->assertStringStartsWith('%PDF', $this->pdf($sesion));
    }

    public function test_excel_separa_la_moneda_en_su_propia_columna(): void
    {
        $cajero = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($cajero);
        $usd = $this->mov($sesion, 'USD', 200);
        $pen = $this->mov($sesion, 'PEN', 100);

        $export = new CashMovementsExport(CashMovement::query());
        $columna = array_search('Moneda', $export->headings(), true);
        $this->assertNotFalse($columna);
        $this->assertSame($columna + 1, array_search('Monto', $export->headings(), true));
        $this->assertSame('US$', $export->map($usd->load('cashSession.cashRegister.branch'))[$columna]);
        $this->assertSame('S/', $export->map($pen->load('cashSession.cashRegister.branch'))[$columna]);
    }
}
