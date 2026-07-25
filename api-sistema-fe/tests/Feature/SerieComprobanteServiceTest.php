<?php

namespace Tests\Feature;

use App\Models\Cash\Branch;
use App\Models\Sale\SerieComprobante;
use App\Services\SerieComprobanteService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Módulo de series de comprobantes — Paso 4. Corre contra
// sistemafe_test_migrations (Postgres real) — nunca contra sv_facturacion
// ni ningún tenant real. TipoComprobante (central) también se redirige,
// porque validarTipoParaCrearSerie() lo consulta.
class SerieComprobanteServiceTest extends TestCase
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
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function servicio(): SerieComprobanteService
    {
        return app(SerieComprobanteService::class);
    }

    private function branch(): Branch
    {
        return Branch::create(['name' => 'Sede de Prueba', 'is_active' => true]);
    }

    private function serie(Branch $branch, string $codigo, string $moneda = 'PEN', string $serieTexto = null): SerieComprobante
    {
        return SerieComprobante::create([
            'branch_id' => $branch->id,
            'tipo_comprobante_codigo' => $codigo,
            'moneda' => $moneda,
            'serie' => $serieTexto ?? ($codigo === 'NV' ? 'NV001' : ($codigo === '01' ? 'F001' : 'B001')),
            'correlativo_actual' => 0,
            'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'),
            'activo' => true,
        ]);
    }

    public function test_validar_tipo_rechaza_fiscal_sin_soporte_greenter(): void
    {
        try {
            $this->servicio()->validarTipoParaCrearSerie('02'); // Recibo por Honorarios, activo_greenter=false
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_validar_tipo_acepta_nv_aunque_activo_greenter_false(): void
    {
        $tipo = $this->servicio()->validarTipoParaCrearSerie('NV');

        $this->assertSame('NV', $tipo->codigo);
        $this->assertFalse($tipo->activo_greenter);
    }

    public function test_validar_tipo_acepta_fiscal_soportado(): void
    {
        $tipo = $this->servicio()->validarTipoParaCrearSerie('01');

        $this->assertSame('01', $tipo->codigo);
        $this->assertTrue($tipo->activo_greenter);
    }

    public function test_serie_nueva_arranca_en_correlativo_actual_cero(): void
    {
        $serie = $this->serie($this->branch(), '01');

        $this->assertSame(0, $serie->correlativo_actual);
    }

    public function test_resolver_serie_sin_crear_lanza_422(): void
    {
        $branch = $this->branch();

        try {
            $this->servicio()->resolverSerie($branch->id, '01', 'PEN');
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_dos_reservas_secuenciales_fiscal_son_consecutivas(): void
    {
        $serie = $this->serie($this->branch(), '01');

        $c1 = $this->servicio()->reservarCorrelativo($serie);
        $c2 = $this->servicio()->reservarCorrelativo($serie->fresh());

        $this->assertSame(1, $c1);
        $this->assertSame(2, $c2);
        $this->assertSame(2, $serie->fresh()->correlativo_actual);
    }

    public function test_dos_reservas_secuenciales_nv_son_consecutivas(): void
    {
        $serie = $this->serie($this->branch(), 'NV');

        $c1 = $this->servicio()->reservarCorrelativo($serie);
        $c2 = $this->servicio()->reservarCorrelativo($serie->fresh());

        $this->assertSame(1, $c1);
        $this->assertSame(2, $c2);
    }

    public function test_unique_constraint_rechaza_series_duplicadas(): void
    {
        $branch = $this->branch();
        $this->serie($branch, '01', 'PEN', 'F001');

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->serie($branch, '01', 'PEN', 'F999'); // mismo branch+tipo+moneda, distinto texto de serie
    }

    public function test_misma_sucursal_puede_tener_dos_series_del_mismo_tipo_en_monedas_distintas(): void
    {
        $branch = $this->branch();
        $serieSoles = $this->serie($branch, '01', 'PEN', 'F001');
        $serieDolares = $this->serie($branch, '01', 'USD', 'F002');

        $this->assertNotSame($serieSoles->id, $serieDolares->id);
        $this->assertSame(0, $serieSoles->correlativo_actual);
        $this->assertSame(0, $serieDolares->correlativo_actual);
    }

    // ── Lock real entre dos conexiones Postgres — mismo patrón que
    // ReservarCorrelativoTest::test_lock_bloquea_segunda_conexion... ──────
    // Rompe a propósito el patrón "cero persistencia real" del resto de la
    // clase: un lock de fila entre dos sesiones Postgres distintas solo es
    // observable si la fila está commiteada de verdad. Se compensa con
    // limpieza manual garantizada en finally{} y reabriendo una transacción
    // vacía para que tearDown() no falle.
    public function test_lock_bloquea_segunda_conexion_sobre_serie_nv(): void
    {
        DB::commit();
        DB::connection('central')->commit();

        $branch = Branch::create(['name' => 'Sede Lock NV', 'is_active' => true]);
        $serie = SerieComprobante::create([
            'branch_id' => $branch->id,
            'tipo_comprobante_codigo' => 'NV',
            'moneda' => 'PEN',
            'serie' => 'NV900',
            'correlativo_actual' => 0,
            'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'),
            'activo' => true,
        ]);

        $bloqueada = false;
        $mensajeError = null;

        try {
            DB::connection('pgsql')->beginTransaction();
            DB::connection('pgsql')->select(
                'select * from serie_comprobantes where id = ? for update',
                [$serie->id]
            );

            config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
            DB::purge('pgsql_b');
            DB::connection('pgsql_b')->beginTransaction();
            DB::connection('pgsql_b')->statement("SET LOCAL lock_timeout = '300ms'");

            try {
                DB::connection('pgsql_b')->select(
                    'select * from serie_comprobantes where id = ? for update',
                    [$serie->id]
                );
            } catch (\Throwable $e) {
                $bloqueada = true;
                $mensajeError = $e->getMessage();
            }

            DB::connection('pgsql_b')->rollBack();
            DB::connection('pgsql')->rollBack();
        } finally {
            DB::connection('pgsql')->table('serie_comprobantes')->where('id', $serie->id)->delete();
            DB::connection('pgsql')->table('branches')->where('id', $branch->id)->delete();

            DB::beginTransaction();
            DB::connection('central')->beginTransaction();
        }

        $this->assertTrue(
            $bloqueada,
            'Se esperaba que la segunda conexión no pudiera tomar el lock de serie_comprobantes mientras la primera lo sostiene abierto.'
        );
        $this->assertNotNull($mensajeError);
    }
}
