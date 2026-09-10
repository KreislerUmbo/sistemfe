<?php

namespace Tests\Feature;

use App\Models\RoleAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MenuResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Fase 1a (plan-modulo-menus-y-roles.md §9.6/§4.3/§9.7) — confirma que los
// eventos de Spatie (recién habilitados, config('permission.events_enabled'))
// disparan de verdad el listener, que este escribe role_audit_logs con los
// datos correctos, y que nunca rompe la asignación de permiso/rol en sí
// (mismo criterio "modo sombra nunca bloquea" de Fase 0c, acá aplicado a
// "el listener nunca revienta la operación real").
class RolePermissionChangedListenerTest extends TestCase
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

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        DB::table('roles')->insert([
            'id' => 1,
            'name' => 'test-role-default',
            'guard_name' => 'api',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    public function test_dar_permiso_a_un_usuario_registra_auditoria_con_el_actor_autenticado(): void
    {
        $actor = User::factory()->create(['email' => 'actor@test.local']);
        Auth::guard('api')->setUser($actor);

        $destino = User::factory()->create();
        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'prueba.permiso']);
        $destino->givePermissionTo('prueba.permiso');

        $this->assertDatabaseHas('role_audit_logs', [
            'actor_user_id' => $actor->id,
            'actor_email' => 'actor@test.local',
            'target_type' => 'user',
            'target_id' => $destino->id,
            'accion' => 'permission_attached',
        ]);

        $log = RoleAuditLog::where('target_id', $destino->id)->where('accion', 'permission_attached')->first();
        $this->assertSame(['prueba.permiso'], $log->detalle);
    }

    // Regresión real encontrada en verificación en vivo contra sandbox (no
    // por ningún test — assertDatabaseHas no valida CANTIDAD): Laravel
    // auto-descubre listeners cuyo único parámetro está tipado como la clase
    // del evento (exactamente la convención de los 4 métodos de este
    // listener) — registrarlos TAMBIÉN a mano en un Event::listen() los
    // duplicaba, generando 2 filas de auditoría por cada attach/detach real.
    public function test_un_solo_cambio_genera_exactamente_una_fila_de_auditoria(): void
    {
        $destino = User::factory()->create();
        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'prueba.permiso']);

        $destino->givePermissionTo('prueba.permiso');

        $this->assertDatabaseCount('role_audit_logs', 1);
    }

    public function test_quitar_permiso_a_un_usuario_registra_auditoria(): void
    {
        $destino = User::factory()->create();
        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'prueba.permiso']);
        $destino->givePermissionTo('prueba.permiso');
        $destino->revokePermissionTo('prueba.permiso');

        $this->assertDatabaseHas('role_audit_logs', [
            'target_type' => 'user',
            'target_id' => $destino->id,
            'accion' => 'permission_detached',
        ]);
    }

    public function test_dar_permiso_a_un_rol_registra_target_type_role(): void
    {
        $role = Role::create(['guard_name' => 'api', 'name' => 'Rol De Prueba']);
        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'prueba.permiso']);
        $role->givePermissionTo('prueba.permiso');

        $this->assertDatabaseHas('role_audit_logs', [
            'target_type' => 'role',
            'target_id' => $role->id,
            'target_label' => 'Rol De Prueba',
            'accion' => 'permission_attached',
        ]);
    }

    public function test_asignar_rol_a_un_usuario_registra_role_attached(): void
    {
        $role = Role::create(['guard_name' => 'api', 'name' => 'Rol De Prueba']);
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertDatabaseHas('role_audit_logs', [
            'target_type' => 'user',
            'target_id' => $user->id,
            'accion' => 'role_attached',
        ]);

        $log = RoleAuditLog::where('target_id', $user->id)->where('accion', 'role_attached')->first();
        $this->assertSame(['Rol De Prueba'], $log->detalle);
    }

    public function test_sin_actor_autenticado_igual_registra_con_actor_null(): void
    {
        $destino = User::factory()->create();
        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'prueba.permiso']);
        $destino->givePermissionTo('prueba.permiso');

        $this->assertDatabaseHas('role_audit_logs', [
            'target_id' => $destino->id,
            'actor_user_id' => null,
        ]);
    }

    public function test_no_rompe_la_asignacion_real_aunque_la_invalidacion_de_cache_falle(): void
    {
        // Sin tenancy inicializada (caso real de este test) — invalidarTenantActivo()
        // hace no-op con un warning, nunca una excepción que se propague.
        $destino = User::factory()->create();
        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'prueba.permiso']);

        $destino->givePermissionTo('prueba.permiso');

        $this->assertTrue($destino->fresh()->hasPermissionTo('prueba.permiso'));
    }
}
