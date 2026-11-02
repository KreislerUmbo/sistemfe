<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\MenuResolver;
use App\Models\Central\MenuItem;
use Database\Seeders\AgenciaViajesRolesSeeder;
use Database\Seeders\MenuItemsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// T-063 — el menú real (MenuItemsSeeder) por giro. Retail y Créditos se comparan contra una foto
// del árbol tomada ANTES de reordenar Agencia (tests/Fixtures/menu_*.json); Agencia contra el
// árbol aprobado en la obs. 17 de docs/requerimientos/observaciones-pruebas-usuario.md.
class MenuGirosTest extends TestCase
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
            'cache.default' => 'array',
            'plataforma.tenants' => ['t-menu-plataforma'],
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        app('cache')->forgetDriver();
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        if (! DB::table('roles')->where('id', 1)->exists()) {
            DB::table('roles')->insert(['id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);
            DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
        }

        (new MenuItemsSeeder())->run();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    protected function superAdmin(): User
    {
        $rol = Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super-Admin']);
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user->fresh();
    }

    protected function arbol(User $user, string $giro, string $tenantId): array
    {
        return (new MenuResolver())->paraUsuario($user, Tenant::make(['id' => $tenantId, 'giro' => $giro]));
    }

    /** Árbol reducido a "Etiqueta" / [hijos] para leerlo y compararlo como el de la obs. 17. */
    protected function etiquetas(array $nodos): array
    {
        $lista = [];
        foreach ($nodos as $nodo) {
            $lista[] = $nodo['hijos'] === [] ? $nodo['label'] : [$nodo['label'] => $this->etiquetas($nodo['hijos'])];
        }

        return $lista;
    }

    protected function fixture(string $nombre): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/{$nombre}.json")), true, 512, JSON_THROW_ON_ERROR);
    }

    // CA3
    public function test_retail_conserva_el_arbol_de_antes(): void
    {
        $this->assertSame($this->fixture('menu_retail'), $this->arbol($this->superAdmin(), 'retail', 't-menu-retail'));
    }

    public function test_retail_en_el_tenant_plataforma_conserva_el_arbol_de_antes(): void
    {
        $this->assertSame($this->fixture('menu_retail_plataforma'), $this->arbol($this->superAdmin(), 'retail', 't-menu-plataforma'));
    }

    public function test_creditos_conserva_el_arbol_de_antes(): void
    {
        $this->assertSame($this->fixture('menu_creditos'), $this->arbol($this->superAdmin(), 'creditos', 't-menu-creditos'));
    }

    // CA1 — el árbol aprobado en la obs. 17 (sin Seguimiento ni Reportes comerciales: los agregan T-051 y T-062).
    public function test_agencia_ve_el_arbol_de_la_obs_17(): void
    {
        $esperado = [
            'Inicio',
            ['VENTAS' => ['Clientes', 'Cotizaciones', 'Venta directa']],
            ['VIAJES' => ['Reservas', 'Salidas operativas', 'Reporte operativo']],
            ['FINANZAS' => [
                ['Comprobantes' => ['Mis ventas', 'Notas de crédito/débito']],
                ['Cobros y anticipos' => ['Ventas por cobrar', 'Anticipos']],
                ['Caja' => ['Turno activo', 'Historial y reportes']],
            ]],
            ['CATÁLOGOS' => ['Tours y paquetes', 'Proveedores', 'Destinos', 'Guías', 'Temporadas']],
            ['ADMINISTRACIÓN' => [
                'Configuración de agencia',
                ['Empresa y sucursales' => ['Datos de la empresa', 'Sucursales']],
                ['Configuración de caja' => ['Cajas', 'Métodos de pago', 'Conceptos de caja', 'Proveedores de gastos']],
                ['Series y numeración' => ['Series de comprobantes', 'Códigos de agencia']],
                ['Usuarios y permisos' => ['Usuarios', 'Roles y permisos']],
            ]],
            ['AYUDA' => ['Manuales y videos']],
        ];

        $this->assertSame($esperado, $this->etiquetas($this->arbol($this->superAdmin(), 'agencia_viajes', 't-menu-agencia')));
    }

    // Aunque el tenant de agencia fuera el de la plataforma, no vuelven los grupos de retail.
    public function test_agencia_no_muestra_los_grupos_de_retail_ni_en_el_tenant_plataforma(): void
    {
        $raiz = array_column($this->arbol($this->superAdmin(), 'agencia_viajes', 't-menu-plataforma'), 'label');

        foreach (['Comercial', 'Caja', 'Configuración', 'Accesos', 'Capacitación', 'Portal web', 'Agencia de viajes'] as $oculto) {
            $this->assertNotContains($oculto, $raiz);
        }
    }

    // CA2 — el vendedor ve solo lo que sus permisos permiten; los grupos sin hijos visibles desaparecen.
    public function test_vendedor_de_agencia_ve_ventas_viajes_y_su_caja_sin_grupos_vacios(): void
    {
        (new AgenciaViajesRolesSeeder())->run();
        $vendedor = User::factory()->create();
        $vendedor->assignRole('Vendedor de agencia');

        $arbol = $this->etiquetas($this->arbol($vendedor->fresh(), 'agencia_viajes', 't-menu-agencia'));

        $this->assertSame([
            'Inicio',
            ['VENTAS' => ['Clientes', 'Cotizaciones', 'Venta directa']],
            ['VIAJES' => ['Reservas', 'Salidas operativas', 'Reporte operativo']],
            ['FINANZAS' => [['Caja' => ['Turno activo']]]],
            ['AYUDA' => ['Manuales y videos']],
        ], $arbol);
    }

    // T-078 CA1 — el reparto por rol de Clientes, Anticipos y Manuales.
    public function test_t078_cada_rol_de_agencia_recibe_sus_permisos_de_clientes_anticipos_y_manuales(): void
    {
        (new AgenciaViajesRolesSeeder())->run();

        $esperado = [
            'Administrador de agencia' => ['register_client', 'list_client', 'edit_client', 'list_advance', 'register_advance', 'refund_advance', 'register_recurso'],
            'Supervisor' => ['register_client', 'list_client', 'edit_client', 'list_advance', 'register_recurso'],
            'Vendedor de agencia' => ['register_client', 'list_client', 'edit_client', 'register_recurso'],
            'Contador' => ['list_client', 'list_advance', 'register_recurso'],
        ];
        $todos = ['register_client', 'list_client', 'edit_client', 'delete_client', 'list_advance', 'register_advance', 'refund_advance', 'register_recurso'];

        foreach ($esperado as $rol => $permisos) {
            $tiene = Role::findByName($rol, 'api')->permissions()->pluck('name')->all();
            foreach ($todos as $permiso) {
                $this->assertSame(in_array($permiso, $permisos, true), in_array($permiso, $tiene, true), "{$rol}: {$permiso}");
            }
        }
    }

    // T-078 CA2 y CA3 — aditivo e idempotente.
    public function test_t078_el_seeder_conserva_lo_que_el_dueno_agrego_y_no_duplica(): void
    {
        (new AgenciaViajesRolesSeeder())->run();
        $vendedor = Role::findByName('Vendedor de agencia', 'api');
        $vendedor->givePermissionTo(Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'delete_client']));
        $antes = $vendedor->permissions()->count();

        (new AgenciaViajesRolesSeeder())->run();

        $vendedor = Role::findByName('Vendedor de agencia', 'api');
        $this->assertTrue($vendedor->hasPermissionTo('delete_client'));
        $this->assertSame($antes, $vendedor->permissions()->count());
        $this->assertSame(1, Permission::where('guard_name', 'api')->where('name', 'list_advance')->count());
    }

    // T-078 CA4 — con el menú de T-063, un usuario de rol (no Super-Admin) ve esas secciones.
    public function test_t078_administrador_de_agencia_ve_clientes_anticipos_y_manuales_y_el_vendedor_clientes(): void
    {
        (new AgenciaViajesRolesSeeder())->run();
        $admin = User::factory()->create();
        $admin->assignRole('Administrador de agencia');
        $vendedor = User::factory()->create();
        $vendedor->assignRole('Vendedor de agencia');

        $hojas = fn (array $nodos) => collect($this->etiquetas($nodos))->flatten()->all();

        $deAdmin = $hojas($this->arbol($admin->fresh(), 'agencia_viajes', 't-menu-agencia'));
        foreach (['Clientes', 'Anticipos', 'Manuales y videos'] as $item) {
            $this->assertContains($item, $deAdmin);
        }

        $deVendedor = $hojas($this->arbol($vendedor->fresh(), 'agencia_viajes', 't-menu-agencia'));
        $this->assertContains('Clientes', $deVendedor);
        $this->assertNotContains('Anticipos', $deVendedor);
    }

    // CA4
    public function test_el_seeder_es_idempotente(): void
    {
        $columnas = ['codigo', 'parent_id', 'label', 'orden', 'giros_excluidos'];
        $antes = MenuItem::orderBy('codigo')->get($columnas)->toArray();

        (new MenuItemsSeeder())->run();

        $this->assertSame($antes, MenuItem::orderBy('codigo')->get($columnas)->toArray());
        $this->assertSame(MenuItem::count(), MenuItem::distinct('codigo')->count('codigo'));
    }
}
