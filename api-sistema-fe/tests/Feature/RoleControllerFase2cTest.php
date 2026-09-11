<?php

namespace Tests\Feature;

use App\Http\Controllers\Role\RoleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Fase 2c (plan-modulo-menus-y-roles.md §7) — el checklist de permisos del
// módulo Roles vivía hardcodeado en el frontend (types/roles.ts::PERMISOS)
// y se demostró que se queda desactualizado (varios módulos reales
// quedaron con permisos inasignables desde la UI hasta que alguien se
// acordaba de sumarlos a mano — ver comentarios del propio archivo). Este
// test cubre la parte de backend: RoleController::index() ahora también
// devuelve el catálogo REAL de permisos del tenant (Spatie), para que el
// frontend pueda cruzarlo contra su catálogo curado y no deje ninguno
// invisible.
class RoleControllerFase2cTest extends TestCase
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

    public function test_index_devuelve_el_catalogo_real_de_permisos_del_tenant(): void
    {
        Permission::create(['name' => 'cotizaciones.ver_todas', 'guard_name' => 'api']);
        Permission::create(['name' => 'reservas.editar', 'guard_name' => 'api']);

        $response = app(RoleController::class)->index(new Request());
        $body = $response->getData(true);

        $this->assertArrayHasKey('permisos_disponibles', $body);
        $this->assertContains('cotizaciones.ver_todas', $body['permisos_disponibles']);
        $this->assertContains('reservas.editar', $body['permisos_disponibles']);
    }

    public function test_permisos_disponibles_no_incluye_permisos_de_otro_guard(): void
    {
        Permission::create(['name' => 'solo_api', 'guard_name' => 'api']);
        Permission::create(['name' => 'solo_web', 'guard_name' => 'web']);

        $response = app(RoleController::class)->index(new Request());
        $body = $response->getData(true);

        $this->assertContains('solo_api', $body['permisos_disponibles']);
        $this->assertNotContains('solo_web', $body['permisos_disponibles']);
    }
}
