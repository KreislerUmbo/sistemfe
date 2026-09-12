<?php

namespace Tests\Feature;

use Database\Seeders\PermissionsDemoSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// plan-modulo-menus-y-roles.md — hallazgo real (12-sep-2026): un tenant retail
// nuevo, provisionado con este seeder, arrancaba sin poder abrir caja, emitir
// factura, ni usar series de comprobante — módulos construidos DESPUÉS de
// escribir el seeder original, nunca retro-agregados. Confirmado comparando
// contra los 71 permisos reales de `umbo` (39 de diferencia). Este test cubre
// que el seeder actualizado ya no deja ese hueco.
class PermissionsDemoSeederTest extends TestCase
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
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_cajero_y_vendedor_pueden_abrir_caja(): void
    {
        (new PermissionsDemoSeeder())->run();

        $cajero = Role::where('guard_name', 'api')->where('name', 'Cajero')->first();
        $vendedor = Role::where('guard_name', 'api')->where('name', 'Vendedor')->first();

        $this->assertTrue($cajero->hasPermissionTo('cash.open_session'));
        $this->assertTrue($vendedor->hasPermissionTo('cash.open_session'));
    }

    public function test_contador_puede_emitir_comprobantes_y_administrar_series(): void
    {
        (new PermissionsDemoSeeder())->run();

        $contador = Role::where('guard_name', 'api')->where('name', 'Contador')->first();

        $this->assertTrue($contador->hasPermissionTo('emitir_factura'));
        $this->assertTrue($contador->hasPermissionTo('emitir_boleta'));
        $this->assertTrue($contador->hasPermissionTo('emitir_nota_venta'));
        $this->assertTrue($contador->hasPermissionTo('register_serie_comprobante'));
        $this->assertTrue($contador->hasPermissionTo('list_serie_comprobante'));
        $this->assertTrue($contador->hasPermissionTo('edit_serie_comprobante'));
        $this->assertTrue($contador->hasPermissionTo('can_switch_branch'));
    }

    public function test_no_inventa_asignaciones_de_configuracion_a_roles_de_negocio(): void
    {
        (new PermissionsDemoSeeder())->run();

        // Fiel al estado real de umbo: nadie fuera de Super-Admin administra
        // Sucursales/Cajas/Métodos de Pago/Proveedores/Conceptos de Caja ni ve
        // el dashboard consolidado de caja (cash.view_all) — son pantallas de
        // configuración, no de operación diaria.
        foreach (['Contador', 'Jefe de Ventas', 'Jefe de Almacen', 'Cajero', 'Vendedor', 'Cliente'] as $nombre) {
            $rol = Role::where('guard_name', 'api')->where('name', $nombre)->first();
            $this->assertFalse($rol->hasPermissionTo('list_branch'), "{$nombre} no debería administrar Sucursales.");
            $this->assertFalse($rol->hasPermissionTo('cash.view_all'), "{$nombre} no debería ver el dashboard consolidado de Caja.");
        }
    }

    public function test_permisos_nuevos_existen_como_filas_reales(): void
    {
        (new PermissionsDemoSeeder())->run();

        foreach (['cash.open_session', 'cash.view_all', 'list_branch', 'list_commercial_quote', 'anular-cuota-credito'] as $permiso) {
            $this->assertTrue(
                \Spatie\Permission\Models\Permission::where('guard_name', 'api')->where('name', $permiso)->exists(),
                "El permiso {$permiso} debería existir como fila real."
            );
        }
    }

    public function test_es_idempotente(): void
    {
        (new PermissionsDemoSeeder())->run();
        (new PermissionsDemoSeeder())->run();

        $cajero = Role::where('guard_name', 'api')->where('name', 'Cajero')->get();
        $this->assertCount(1, $cajero, 'No debería duplicar el rol al correr dos veces.');
        $this->assertTrue($cajero->first()->hasPermissionTo('cash.open_session'));
    }
}
