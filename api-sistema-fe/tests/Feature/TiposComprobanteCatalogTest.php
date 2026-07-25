<?php

namespace Tests\Feature;

use App\Models\Sale\TipoComprobante;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Módulo de series de comprobantes — Paso 4. Verifica el catálogo central
// sembrado por 2026_07_19_150000_create_tipos_comprobante_table.php contra
// sistemafe_test_migrations (Postgres real). TipoComprobante usa
// CentralConnection, así que además de redirigir 'pgsql' (patrón ya
// establecido en GreenterServiceFormaPagoTest/ReservarCorrelativoTest) hay
// que redirigir también la conexión 'central' — nunca contra sv_facturacion.
class TiposComprobanteCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => env('DB_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => env('DB_PORT', '5432'),
            'database.connections.pgsql.database' => 'sistemafe_test_migrations',
            'database.connections.pgsql.username' => env('DB_USERNAME', 'root'),
            'database.connections.pgsql.password' => env('DB_PASSWORD', ''),
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        DB::connection('central')->beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    public function test_catalogo_sunat_tiene_los_14_codigos_confirmados_mas_nv(): void
    {
        $codigos = TipoComprobante::orderBy('codigo')->pluck('codigo')->all();

        foreach (['00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', 'NV'] as $esperado) {
            $this->assertContains($esperado, $codigos, "Falta el código '{$esperado}' en el catálogo.");
        }

        $this->assertCount(16, $codigos);
    }

    public function test_nv_es_documento_interno_no_fiscal(): void
    {
        $nv = TipoComprobante::find('NV');

        $this->assertNotNull($nv);
        $this->assertFalse($nv->es_documento_sunat);
        $this->assertFalse($nv->activo_greenter);
        $this->assertSame('Nota de venta', $nv->nombre);
    }

    public function test_activo_greenter_solo_en_01_03_07_08(): void
    {
        $activos = TipoComprobante::where('activo_greenter', true)->orderBy('codigo')->pluck('codigo')->all();

        $this->assertSame(['01', '03', '07', '08'], $activos);
    }

    public function test_todos_los_codigos_sunat_tienen_es_documento_sunat_true(): void
    {
        $sunatCodigos = ['00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14'];

        $count = TipoComprobante::whereIn('codigo', $sunatCodigos)
            ->where('es_documento_sunat', true)
            ->count();

        $this->assertSame(15, $count);
    }

    // Invariante activo_greenter=true → es_documento_sunat=true, forzada por
    // un CHECK real de Postgres (no solo confianza en el seeder) — el caso
    // exacto que rompería el guard de enviarSunat()/store() si se colara.
    public function test_check_constraint_rechaza_activo_greenter_sin_es_documento_sunat(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::connection('central')->table('tipos_comprobante')->insert([
            'codigo' => 'ZZ',
            'nombre' => 'Tipo inválido de prueba',
            'es_documento_sunat' => false,
            'activo_greenter' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
