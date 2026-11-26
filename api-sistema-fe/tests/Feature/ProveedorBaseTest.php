<?php

namespace Tests\Feature;

use App\Models\Proveedor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// T-133 (CA6) — el proveedor del sistema base en un tenant de tienda, por HTTP real: mismo patrón
// que AdminPortalCentralRoutesTenancyTest (base física descartable, subdominio y JWT de verdad).
class ProveedorBaseTest extends TestCase
{
    private ?Tenant $tenant = null;

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

        if (! Schema::connection('central')->hasTable('tenants')) {
            Artisan::call('migrate', [
                '--path' => [
                    'database/migrations/2019_09_15_000010_create_tenants_table.php',
                    'database/migrations/2019_09_15_000020_create_domains_table.php',
                    'database/migrations/2026_07_13_120000_add_status_to_tenants_table.php',
                    'database/migrations/2026_07_27_090000_add_giro_tipo_sunat_modo_to_tenants_table.php',
                ],
                '--force' => true,
            ]);
        }

        $id = 'test-t133-'.Str::lower(Str::random(8));
        $this->tenant = Tenant::create(['id' => $id, 'ruc' => $id, 'razon_social' => "Tienda {$id}", 'giro' => 'retail']);
        $this->tenant->domains()->create(['domain' => $id]);
    }

    protected function tearDown(): void
    {
        // La petición HTTP deja la tenancy abierta: sin cerrarla, Postgres no deja borrar la base.
        tenancy()->end();
        DB::purge('tenant');

        if ($tenant = $this->tenant?->fresh()) {
            if ($tenant->database()->manager()->databaseExists($tenant->database()->getName())) {
                $tenant->database()->manager()->deleteDatabase($tenant);
            }
            $tenant->delete();
        }

        parent::tearDown();
    }

    /** @param list<string> $permisos */
    private function token(array $permisos): string
    {
        return $this->tenant->run(function () use ($permisos) {
            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            if (! DB::table('roles')->where('id', 1)->exists()) {
                DB::table('roles')->insert([
                    'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $user = User::factory()->create();
            foreach ($permisos as $permiso) {
                Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
                $user->givePermissionTo($permiso);
            }

            return auth('api')->login($user->fresh());
        });
    }

    private function api(string $token, string $metodo, string $ruta, array $datos = [])
    {
        auth()->forgetGuards();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->json($metodo, "http://{$this->tenant->id}.sistemafe.test/api/{$ruta}", $datos);
    }

    public function test_ca4_un_tenant_nuevo_queda_sin_la_tabla_vieja(): void
    {
        $this->assertSame([true, false], $this->tenant->run(
            fn () => [Schema::hasTable('proveedores'), Schema::hasTable('suppliers')]
        ));
    }

    public function test_ca6_con_gestionar_se_crea_edita_y_busca_en_una_tienda(): void
    {
        $token = $this->token(['proveedores.gestionar']);

        $id = $this->api($token, 'POST', 'proveedores-base', [
            'razon_social' => 'Distribuidora de Agua SAC', 'numero_documento' => '20999888777', 'telefono' => '999111222',
        ])->assertOk()->json('proveedor.id');

        $this->api($token, 'GET', 'proveedores-base?search=20999888')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('proveedores.0.razon_social', 'Distribuidora de Agua SAC')
            ->assertJsonPath('proveedores.0.estado', true)
            ->assertJsonMissingPath('proveedores.0.margen_default_valor');

        // Edición parcial: lo que no viene no se borra.
        $this->api($token, 'PUT', "proveedores-base/{$id}", ['estado' => false])->assertOk()
            ->assertJsonPath('proveedor.estado', false)
            ->assertJsonPath('proveedor.telefono', '999111222');

        $this->api($token, 'GET', "proveedores-base/{$id}")->assertOk()
            ->assertJsonPath('proveedor.razon_social', 'Distribuidora de Agua SAC');

        $this->api($token, 'POST', 'proveedores-base', ['razon_social' => ''])->assertStatus(422);
    }

    public function test_ca6_sin_permiso_recibe_403_y_solo_ver_no_escribe(): void
    {
        $sinPermiso = $this->token([]);

        $this->api($sinPermiso, 'GET', 'proveedores-base')->assertStatus(403);
        $this->api($sinPermiso, 'POST', 'proveedores-base', ['razon_social' => 'X'])->assertStatus(403);

        $soloVer = $this->token(['proveedores.ver']);

        $this->api($soloVer, 'GET', 'proveedores-base')->assertOk();
        $this->api($soloVer, 'POST', 'proveedores-base', ['razon_social' => 'X'])->assertStatus(403);
        $this->api($soloVer, 'PUT', 'proveedores-base/1', ['razon_social' => 'X'])->assertStatus(403);

        $this->assertSame(0, $this->tenant->run(fn () => Proveedor::count()));
    }

    // T-134 (CA5) — el alta rápida es POST proveedores-base: sin gestionar no se puede llamar.
    // De paso, el alta rápida completa (aviso de RUC incluido) en un tenant recién creado.
    public function test_t134_ca5_el_alta_rapida_pide_gestionar(): void
    {
        $alta = ['razon_social' => 'Hotel del Valle SAC', 'tipo_documento' => 'RUC', 'numero_documento' => '20134000005',
            'direccion_fiscal' => 'Av. Fiscal 1', 'ciudad' => 'Tarapoto'];

        $soloVer = $this->token(['proveedores.ver']);
        $this->api($soloVer, 'POST', 'proveedores-base', $alta)->assertStatus(403);
        $this->api($soloVer, 'POST', 'proveedores-base', $alta + ['confirmar_duplicado' => true])->assertStatus(403);

        $gestiona = $this->token(['proveedores.gestionar']);
        $this->api($gestiona, 'POST', 'proveedores-base', $alta)->assertOk()
            ->assertJsonPath('proveedor.direccion_fiscal', 'Av. Fiscal 1')
            ->assertJsonPath('proveedor.es_no_domiciliado', false)
            ->assertJsonPath('proveedor.datos_faltantes', ['teléfono']);
        $this->api($gestiona, 'POST', 'proveedores-base', $alta)->assertStatus(409)
            ->assertJsonPath('existentes.0.ciudad', 'Tarapoto');
    }

    public function test_ca6_las_rutas_turisticas_siguen_pidiendo_agencia_proveedores(): void
    {
        $token = $this->token(['proveedores.ver', 'proveedores.gestionar']);

        $this->api($token, 'GET', 'proveedores')->assertStatus(403);
        $this->api($token, 'POST', 'proveedores', ['razon_social' => 'X'])->assertStatus(403);
    }
}
