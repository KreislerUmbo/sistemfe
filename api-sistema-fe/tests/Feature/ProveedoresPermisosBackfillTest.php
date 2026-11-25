<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AgenciaViajesRolesSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// T-133 (CA5, CA7) — backfill de proveedores.ver / proveedores.gestionar: nadie pierde acceso y
// nadie gana de más.
class ProveedoresPermisosBackfillTest extends TestCase
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
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        parent::tearDown();
    }

    private function permiso(string $nombre): Permission
    {
        return Permission::firstOrCreate(['guard_name' => 'api', 'name' => $nombre]);
    }

    private function rol(string $nombre, array $permisos): Role
    {
        $rol = Role::create(['guard_name' => 'api', 'name' => $nombre]);
        foreach ($permisos as $permiso) {
            $rol->givePermissionTo($this->permiso($permiso));
        }

        return $rol;
    }

    private function backfill(): void
    {
        (require database_path('migrations/tenant/core/2026_11_25_200000_add_proveedores_permissions.php'))->up();
    }

    private function nuevos(Role|User $de): array
    {
        $permisos = $de instanceof Role ? $de->fresh()->permissions : $de->fresh()->getDirectPermissions();

        return $permisos->pluck('name')->filter(fn (string $n) => str_starts_with($n, 'proveedores.'))->sort()->values()->all();
    }

    public function test_ca5_cada_rol_recibe_lo_que_ya_podia_hacer_y_nada_mas(): void
    {
        $esperado = [
            't133-agencia' => [['agencia.proveedores'], ['proveedores.gestionar', 'proveedores.ver']],
            't133-caja-crea' => [['list_supplier', 'register_supplier'], ['proveedores.gestionar', 'proveedores.ver']],
            't133-caja-mira' => [['list_supplier'], ['proveedores.ver']],
            't133-caja-edita' => [['edit_supplier', 'delete_supplier'], ['proveedores.ver']],
            't133-sin-nada' => [['agencia.destinos'], []],
        ];
        $roles = [];
        foreach ($esperado as $nombre => [$viejos]) {
            $roles[$nombre] = $this->rol($nombre, $viejos);
        }

        $this->backfill();
        $this->backfill(); // idempotente

        foreach ($esperado as $nombre => [$viejos, $nuevos]) {
            $this->assertSame($nuevos, $this->nuevos($roles[$nombre]), $nombre);
            foreach ($viejos as $viejo) {
                $this->assertTrue($roles[$nombre]->fresh()->hasPermissionTo($viejo), "{$nombre} perdió {$viejo}");
            }
        }
    }

    public function test_ca5_tambien_el_permiso_directo_de_un_usuario(): void
    {
        if (! DB::table('roles')->where('id', 1)->exists()) {
            DB::table('roles')->insert(['id' => 1, 'name' => 't133-base', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->permiso('list_supplier');
        $user = User::factory()->create();
        $user->givePermissionTo('list_supplier');

        $this->backfill();

        $this->assertSame(['proveedores.ver'], $this->nuevos($user));
    }

    public function test_ca7_en_un_tenant_nuevo_de_agencia_quien_tiene_agencia_proveedores_tiene_ver_y_gestionar(): void
    {
        (new AgenciaViajesRolesSeeder)->run();

        $roles = Role::where('guard_name', 'api')
            ->whereHas('permissions', fn ($q) => $q->where('name', 'agencia.proveedores'))->get();

        $this->assertNotEmpty($roles);
        foreach ($roles as $rol) {
            $this->assertTrue($rol->hasPermissionTo('proveedores.ver'), $rol->name);
            $this->assertTrue($rol->hasPermissionTo('proveedores.gestionar'), $rol->name);
        }
    }
}
