<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\TipoCambioAgenciaController;
use App\Models\TipoCambio\TipoCambioSunat;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fase 5 (opcional) del plan de Tipo de Cambio SUNAT — endpoint de solo
// lectura, nunca escribe en tipo_cambio_agencia. Solo necesita la conexión
// 'central' (tipo_cambio_sunat), no toca sistemafe_test_migrations.
class TipoCambioAgenciaSugerenciaSunatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('central')->beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    public function test_devuelve_el_ultimo_valor_venta_disponible(): void
    {
        TipoCambioSunat::create(['fecha' => now()->toDateString(), 'compra' => 3.70, 'venta' => 3.705, 'fuente' => 'decolecta', 'consultado_en' => now()]);

        $respuesta = app(TipoCambioAgenciaController::class)->sugerenciaSunat();

        $data = $respuesta->getData(true);
        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertEquals(3.705, (float) $data['sugerencia']['valor']);
    }

    public function test_devuelve_null_si_no_hay_ningun_dato_disponible(): void
    {
        $respuesta = app(TipoCambioAgenciaController::class)->sugerenciaSunat();

        $data = $respuesta->getData(true);
        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertNull($data['sugerencia']);
    }
}
