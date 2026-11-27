<?php

namespace Tests\Feature\AgenciaViajes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

// T-141 — Retiro de pago_proveedor y cronograma_pago_proveedor. Como el test de T-083
// (ReporteOperativoCheckinTest): se corre la migración dentro de la transacción del test, y en
// Postgres el DDL también se deshace al final.
class RetirarPagoProveedorViejoTest extends TestCase
{
    private const TABLAS = ['pago_proveedor', 'cronograma_pago_proveedor'];

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
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function migracion(): object
    {
        return require database_path('migrations/tenant/verticals/agencia-viajes/2026_11_25_100000_retirar_pago_proveedor_viejo.php');
    }

    public function test_ca1_borra_las_dos_tablas_y_el_down_las_vuelve_a_crear(): void
    {
        $migracion = $this->migracion();

        $migracion->down(); // estado anterior: las dos tablas, vacías
        foreach (self::TABLAS as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "down() debió crear {$tabla}");
        }

        $migracion->up();
        foreach (self::TABLAS as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), "up() debió borrar {$tabla}");
        }

        $migracion->up(); // sin las tablas tampoco falla
    }

    public function test_ca2_con_filas_no_borra_nada(): void
    {
        $migracion = $this->migracion();
        $migracion->down();
        $proveedorId = DB::table('proveedores')->insertGetId(['razon_social' => 'Proveedor T-141']);
        DB::table('cronograma_pago_proveedor')->insert([
            'proveedor_id' => $proveedorId, 'numero_cuota' => 1, 'monto_programado' => 100,
            'fecha_vencimiento' => '2026-12-01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $migracion->up();
            $this->fail('La migración debió detenerse con filas en cronograma_pago_proveedor.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cronograma_pago_proveedor', $e->getMessage());
        }

        foreach (self::TABLAS as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "{$tabla} no debió borrarse");
        }
        $this->assertSame(1, DB::table('cronograma_pago_proveedor')->count());
    }

    public function test_ca3_no_quedan_referencias_en_el_codigo(): void
    {
        $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        $conReferencia = [];
        foreach ($archivos as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }
            $texto = file_get_contents($archivo->getPathname());
            // T-136: el nombre PagoProveedor y el texto 'pago_proveedor' (tipo de movimiento de Caja)
            // vuelven a usarse en el modelo nuevo; lo prohibido es lo viejo.
            if (preg_match('/AgenciaViajes\\\\(Cronograma)?PagoProveedor|CronogramaPagoProveedor|cronograma_pago_proveedor|(table\(|\$table = )[\'"]pago_proveedor[\'"]/', $texto)) {
                $conReferencia[] = str_replace(app_path(), 'app', $archivo->getPathname());
            }
        }

        // Fuera de alcance (motor de recordatorios 11f): el aviso de pago y su entidad se quedan.
        $conReferencia = array_values(array_diff($conReferencia, [
            'app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'AgenciaViajes'.DIRECTORY_SEPARATOR.'Recordatorio.php',
        ]));

        $this->assertSame([], $conReferencia);
    }
}
