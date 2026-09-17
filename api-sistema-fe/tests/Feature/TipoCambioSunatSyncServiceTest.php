<?php

namespace Tests\Feature;

use App\Models\TipoCambio\TipoCambioSunat;
use App\Services\TipoCambio\TipoCambioSanityService;
use App\Services\TipoCambio\TipoCambioSunatSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// Fase 3 del plan de Tipo de Cambio SUNAT — Decolecta primaria (requiere
// token, confirmado en vivo que sin token responde 401), e-api.net.pe
// fallback. Misma protección que TipoCambioSunatModelTest: transacción
// sobre la conexión 'central' real (no hay base de test central separada
// todavía).
class TipoCambioSunatSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('central')->beginTransaction();

        // El sync diario real (Módulo Tipo de Cambio) puede haber dejado
        // filas reales en la BD central compartida de dev — se limpian acá
        // DENTRO de la transacción de este test (tearDown() la revierte),
        // así el dato real vuelve intacto al terminar. Mismo criterio que
        // TipoCambioSunatAplicadoSaleTest. Sin esto, "correr dos veces el
        // mismo día actualiza en vez de duplicar" contaba también la fila
        // real ajena (fecha distinta), rompiendo el assertSame(1, ...).
        TipoCambioSunat::query()->delete();

        config(['services.decolecta.token' => 'fake-token-test']);
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function service(): TipoCambioSunatSyncService
    {
        return new TipoCambioSunatSyncService(new TipoCambioSanityService());
    }

    public function test_guarda_con_decolecta_cuando_responde_ok(): void
    {
        Http::fake([
            'api.decolecta.com/*' => Http::response(['buy_price' => '3.700', 'sell_price' => '3.705', 'date' => '2026-09-08']),
        ]);

        $resultado = $this->service()->sincronizar(Carbon::parse('2026-09-08'));

        $this->assertNotNull($resultado);
        $this->assertSame('decolecta', $resultado->fuente);
        $this->assertEquals(3.700, (float) $resultado->compra);
        $this->assertEquals(3.705, (float) $resultado->venta);
    }

    public function test_usa_fallback_e_api_si_decolecta_falla(): void
    {
        Http::fake([
            'api.decolecta.com/*' => Http::response(null, 500),
            'free.e-api.net.pe/*' => Http::response(['fecha' => '2026-09-08', 'compra' => 3.71, 'venta' => 3.715]),
        ]);

        $resultado = $this->service()->sincronizar(Carbon::parse('2026-09-08'));

        $this->assertNotNull($resultado);
        $this->assertSame('e-api', $resultado->fuente);
        $this->assertEquals(3.715, (float) $resultado->venta);
    }

    public function test_no_guarda_nada_si_ambas_fuentes_fallan_y_alerta_con_log_critical(): void
    {
        Http::fake([
            'api.decolecta.com/*' => Http::response(null, 500),
            'free.e-api.net.pe/*' => Http::response(null, 500),
        ]);
        Log::spy();

        $resultado = $this->service()->sincronizar(Carbon::parse('2026-09-08'));

        $this->assertNull($resultado);
        $this->assertSame(0, TipoCambioSunat::count());
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_no_guarda_nada_si_el_valor_esta_fuera_del_rango_de_sanidad(): void
    {
        Http::fake([
            // 1.0000 — el mismo valor envenenado real encontrado en tipo_cambio_agencia
            'api.decolecta.com/*' => Http::response(['buy_price' => '1.0000', 'sell_price' => '1.0000', 'date' => '2026-09-08']),
        ]);
        Log::spy();

        $resultado = $this->service()->sincronizar(Carbon::parse('2026-09-08'));

        $this->assertNull($resultado);
        $this->assertSame(0, TipoCambioSunat::count());
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_sin_token_decolecta_saltea_directo_al_fallback(): void
    {
        config(['services.decolecta.token' => null]);
        Http::fake([
            'api.decolecta.com/*' => Http::response(['buy_price' => '3.70', 'sell_price' => '3.705']),
            'free.e-api.net.pe/*' => Http::response(['fecha' => '2026-09-08', 'compra' => 3.71, 'venta' => 3.715]),
        ]);

        $resultado = $this->service()->sincronizar(Carbon::parse('2026-09-08'));

        $this->assertSame('e-api', $resultado->fuente);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'decolecta.com'));
    }

    public function test_correr_dos_veces_el_mismo_dia_actualiza_en_vez_de_duplicar(): void
    {
        // Http::fake() encadenado dos veces NO reemplaza el stub anterior
        // (Laravel matchea el primero definido, no el último) — la forma
        // correcta de simular 2 respuestas distintas al mismo endpoint es
        // una sola llamada con Http::sequence().
        Http::fake([
            'api.decolecta.com/*' => Http::sequence()
                ->push(['buy_price' => '3.70', 'sell_price' => '3.705'])
                ->push(['buy_price' => '3.71', 'sell_price' => '3.715']),
        ]);

        $this->service()->sincronizar(Carbon::parse('2026-09-08'));
        $this->service()->sincronizar(Carbon::parse('2026-09-08'));

        $this->assertSame(1, TipoCambioSunat::count());
        $this->assertEquals(3.715, (float) TipoCambioSunat::first()->venta);
    }
}
