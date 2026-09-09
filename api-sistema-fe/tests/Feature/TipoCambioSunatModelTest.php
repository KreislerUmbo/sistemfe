<?php

namespace Tests\Feature;

use App\Models\TipoCambio\TipoCambioSunat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fase 2 del plan de Tipo de Cambio SUNAT — tabla/modelo CENTRAL (dato
// nacional SBS, no depende del tenant, mismo criterio que
// TaxConfig/DetractionCode). A diferencia del resto de la suite (que usa
// sistemafe_test_migrations vía la conexión 'pgsql'), este test corre
// contra la conexión 'central' real (db_tenant_central, fija en
// config/database.php) envuelta en transacción — no hay una base de
// pruebas central separada todavía, así que se protege con
// rollback igual que el resto de la suite protege 'pgsql'.
class TipoCambioSunatModelTest extends TestCase
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

    public function test_el_modelo_resuelve_la_conexion_central_sin_importar_el_tenant_activo(): void
    {
        $fila = TipoCambioSunat::create([
            'fecha' => '2026-09-08',
            'compra' => 3.7000,
            'venta' => 3.7050,
            'fuente' => 'decolecta',
            'consultado_en' => now(),
        ]);

        $this->assertSame('central', $fila->getConnectionName());
        $this->assertSame('db_tenant_central', DB::connection('central')->getDatabaseName());
        $this->assertEquals(3.705, (float) TipoCambioSunat::find($fila->id)->venta);
    }

    public function test_no_permite_dos_filas_para_la_misma_fecha(): void
    {
        TipoCambioSunat::create([
            'fecha' => '2026-09-08', 'compra' => 3.70, 'venta' => 3.705,
            'fuente' => 'decolecta', 'consultado_en' => now(),
        ]);

        $this->expectException(QueryException::class);

        TipoCambioSunat::create([
            'fecha' => '2026-09-08', 'compra' => 3.71, 'venta' => 3.715,
            'fuente' => 'e-api', 'consultado_en' => now(),
        ]);
    }
}
