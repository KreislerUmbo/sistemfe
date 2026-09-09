<?php

namespace Tests\Feature;

use App\Models\TipoCambio\TipoCambioSunat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizarTipoCambioSunatCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('central')->beginTransaction();
        config(['services.decolecta.token' => 'fake-token-test']);
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    public function test_command_acepta_fecha_puntual_para_backfill_manual(): void
    {
        Http::fake([
            'api.decolecta.com/*' => Http::response(['buy_price' => '3.70', 'sell_price' => '3.705']),
        ]);

        $this->artisan('tipo-cambio:sincronizar-sunat', ['--fecha' => '2026-08-01'])
            ->assertExitCode(0);

        $this->assertSame('2026-08-01', TipoCambioSunat::first()->fecha->toDateString());
    }

    public function test_command_no_falla_duro_si_ambas_fuentes_caen(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->artisan('tipo-cambio:sincronizar-sunat')
            ->assertExitCode(0);

        $this->assertSame(0, TipoCambioSunat::count());
    }
}
