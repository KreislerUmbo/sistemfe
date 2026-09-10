<?php

namespace Tests\Feature;

use App\Models\Central\MenuItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MenuResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Fase 1a (plan-modulo-menus-y-roles.md §4.1/§4.2/§4.3) — MenuResolver contra
// Postgres real (sistemafe_test_migrations), mismo patrón de transacción que
// GateBucketARoutesTest/ShadowPermissionMiddlewareTest, con la 'central'
// también envuelta en transacción (menu_items vive ahí).
//
// El Tenant usado en los tests es un Tenant::make() SIN persistir — giro/id
// son atributos reales del modelo (VirtualColumn con getCustomColumns()), no
// hace falta una fila real en `tenants` para que MenuResolver los lea.
class MenuResolverTest extends TestCase
{
    private MenuResolver $resolver;

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
            'cache.default' => 'array',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        app('cache')->forgetDriver();
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

        $this->resolver = new MenuResolver();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function tenant(string $giro = 'agencia_viajes'): Tenant
    {
        return Tenant::make(['id' => 'test-menu-tenant', 'giro' => $giro]);
    }

    private function usuarioConPermisos(array $permisos): User
    {
        $user = User::factory()->create();

        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
        }

        if ($permisos !== []) {
            $user->givePermissionTo($permisos);
        }

        return $user->fresh();
    }

    public function test_filtra_por_permiso_requerido(): void
    {
        MenuItem::create(['codigo' => 'a', 'tipo' => 'enlace', 'label' => 'A', 'ruta' => 'a.index', 'permiso_requerido' => 'ver_a', 'orden' => 1]);
        MenuItem::create(['codigo' => 'b', 'tipo' => 'enlace', 'label' => 'B', 'ruta' => 'b.index', 'permiso_requerido' => 'ver_b', 'orden' => 2]);

        $user = $this->usuarioConPermisos(['ver_a']);

        $arbol = $this->resolver->paraUsuario($user, $this->tenant());

        $this->assertCount(1, $arbol);
        $this->assertSame('a', $arbol[0]['codigo']);
    }

    public function test_item_sin_permiso_requerido_es_siempre_visible(): void
    {
        MenuItem::create(['codigo' => 'libre', 'tipo' => 'enlace', 'label' => 'Libre', 'ruta' => 'libre.index', 'permiso_requerido' => null, 'orden' => 1]);

        $user = $this->usuarioConPermisos([]);

        $arbol = $this->resolver->paraUsuario($user, $this->tenant());

        $this->assertCount(1, $arbol);
        $this->assertSame('libre', $arbol[0]['codigo']);
    }

    public function test_filtra_por_giro(): void
    {
        MenuItem::create(['codigo' => 'universal', 'tipo' => 'enlace', 'label' => 'Universal', 'giro' => null, 'orden' => 1]);
        MenuItem::create(['codigo' => 'solo_agencia', 'tipo' => 'enlace', 'label' => 'Solo agencia', 'giro' => 'agencia_viajes', 'orden' => 2]);
        MenuItem::create(['codigo' => 'solo_retail', 'tipo' => 'enlace', 'label' => 'Solo retail', 'giro' => 'retail', 'orden' => 3]);

        $user = $this->usuarioConPermisos([]);

        $codigosAgencia = collect($this->resolver->paraUsuario($user, $this->tenant('agencia_viajes')))->pluck('codigo')->all();
        $this->assertEqualsCanonicalizing(['universal', 'solo_agencia'], $codigosAgencia);

        $this->resolver->invalidarUsuario('test-menu-tenant', $user->id); // distinto giro = distinto árbol, forzar recálculo
        $codigosRetail = collect($this->resolver->paraUsuario($user, $this->tenant('retail')))->pluck('codigo')->all();
        $this->assertEqualsCanonicalizing(['universal', 'solo_retail'], $codigosRetail);
    }

    public function test_arma_arbol_y_poda_grupos_sin_hijos_visibles(): void
    {
        $grupoVisible = MenuItem::create(['codigo' => 'g1', 'tipo' => 'grupo', 'label' => 'Grupo visible', 'orden' => 1]);
        MenuItem::create(['codigo' => 'g1.hijo', 'parent_id' => $grupoVisible->id, 'tipo' => 'enlace', 'label' => 'Hijo visible', 'ruta' => 'g1.hijo', 'orden' => 1]);

        $grupoVacio = MenuItem::create(['codigo' => 'g2', 'tipo' => 'grupo', 'label' => 'Grupo que queda vacío', 'orden' => 2]);
        MenuItem::create(['codigo' => 'g2.hijo', 'parent_id' => $grupoVacio->id, 'tipo' => 'enlace', 'label' => 'Hijo sin permiso', 'ruta' => 'g2.hijo', 'permiso_requerido' => 'no_lo_tiene', 'orden' => 1]);

        $user = $this->usuarioConPermisos([]);

        $arbol = $this->resolver->paraUsuario($user, $this->tenant());

        $codigos = collect($arbol)->pluck('codigo')->all();
        $this->assertSame(['g1'], $codigos, 'g2 debe podarse: quedó sin ningún hijo visible.');
        $this->assertCount(1, $arbol[0]['hijos']);
        $this->assertSame('g1.hijo', $arbol[0]['hijos'][0]['codigo']);
    }

    public function test_respuesta_no_expone_campos_internos(): void
    {
        MenuItem::create(['codigo' => 'a', 'tipo' => 'enlace', 'label' => 'A', 'icono' => 'icon-a', 'ruta' => 'a.index', 'orden' => 1]);

        $user = $this->usuarioConPermisos([]);
        $arbol = $this->resolver->paraUsuario($user, $this->tenant());

        $this->assertEqualsCanonicalizing(['codigo', 'label', 'icono', 'ruta', 'hijos'], array_keys($arbol[0]));
    }

    public function test_cachea_por_tenant_y_usuario_hasta_invalidar(): void
    {
        MenuItem::create(['codigo' => 'a', 'tipo' => 'enlace', 'label' => 'A', 'ruta' => 'a.index', 'orden' => 1]);

        $user = $this->usuarioConPermisos([]);
        $tenant = $this->tenant();

        $primero = $this->resolver->paraUsuario($user, $tenant);
        $this->assertCount(1, $primero);

        MenuItem::create(['codigo' => 'b', 'tipo' => 'enlace', 'label' => 'B', 'ruta' => 'b.index', 'orden' => 2]);

        $segundo = $this->resolver->paraUsuario($user, $tenant);
        $this->assertCount(1, $segundo, 'Debe seguir sirviendo el árbol cacheado, sin recalcular.');

        $this->resolver->invalidarUsuario('test-menu-tenant', $user->id);

        $tercero = $this->resolver->paraUsuario($user, $tenant);
        $this->assertCount(2, $tercero, 'Tras invalidar, debe reflejar el ítem nuevo.');
    }

    public function test_super_admin_ve_items_sin_tener_el_permiso_asignado(): void
    {
        MenuItem::create(['codigo' => 'restringido', 'tipo' => 'enlace', 'label' => 'Restringido', 'ruta' => 'r.index', 'permiso_requerido' => 'permiso_que_no_tiene', 'orden' => 1]);

        $role = \Spatie\Permission\Models\Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super-Admin']);
        $user = User::factory()->create();
        $user->assignRole($role);

        $arbol = $this->resolver->paraUsuario($user->fresh(), $this->tenant());

        $this->assertCount(1, $arbol);
        $this->assertSame('restringido', $arbol[0]['codigo']);
    }
}
