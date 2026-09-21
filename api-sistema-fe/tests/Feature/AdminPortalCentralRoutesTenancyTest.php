<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// Guardrail (19-sep-2026) — cierra [[project_admin_portal_auth_wrong_db_bug]]:
// el grupo "100% CENTRALES" de routes/api.php (system_categories/systems/
// recursos) solo tenía 'auth:api', sin 'tenant'/'tenant.token'. Sin
// InitializeTenancyBySubdomain, auth:api resolvía el usuario del JWT contra
// la conexión Postgres DEFAULT en vez de la base del tenant dueño real del
// token — evidencia real: un JWT de un tenant autenticó como una persona
// completamente distinta en sv_facturacion, pura coincidencia de PK
// numérica.
//
// Igual que MigrateVerticalesPendientesTest: crea tenants físicos reales y
// descartables (única forma de probar de verdad la resolución por
// subdominio + el guard auth:api contra la base correcta — no es mockeable).
// tearDown() borra las bases físicas siempre.
class AdminPortalCentralRoutesTenancyTest extends TestCase
{
    /** @var Tenant[] */
    private array $tenantsCreados = [];

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

        $this->asegurarTablasCentrales();
    }

    protected function tearDown(): void
    {
        foreach ($this->tenantsCreados as $tenant) {
            $tenant = $tenant->fresh();

            if (! $tenant) {
                continue;
            }

            if ($tenant->database()->manager()->databaseExists($tenant->database()->getName())) {
                $tenant->database()->manager()->deleteDatabase($tenant);
            }

            $tenant->delete();
        }

        parent::tearDown();
    }

    // Mismo patrón que MigrateVerticalesPendientesTest — tenants/domains no
    // forman parte de sistemafe_test_migrations, se agregan una sola vez
    // (idempotente) con las migraciones reales del proyecto.
    private function asegurarTablasCentrales(): void
    {
        if (Schema::connection('central')->hasTable('tenants')) {
            return;
        }

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

    private function crearTenantConDominio(): Tenant
    {
        $id = 'test-auth-' . Str::lower(Str::random(8));

        $tenant = Tenant::create(['id' => $id, 'ruc' => $id, 'razon_social' => "Tenant de prueba {$id}"]);
        // InitializeTenancyBySubdomain resuelve por el PRIMER label del host
        // (Str::before($host, '.')) — domains.domain guarda solo ese
        // segmento, no el FQDN completo (confirmado contra agencia-demo real:
        // domain='agencia-demo', no 'agencia-demo.sistemafe.test').
        $tenant->domains()->create(['domain' => $id]);

        $this->tenantsCreados[] = $tenant;

        return $tenant;
    }

    private function crearUsuarioYToken(Tenant $tenant, string $nombre): string
    {
        return $tenant->run(function () use ($nombre) {
            // users.role_id tiene default(1) + FK a roles — el tenant recién
            // provisto solo corrió tenant/core/, sin PermissionsDemoSeeder
            // (eso es responsabilidad de TenantProvisioningService::provision(),
            // no de Tenant::create() a secas). Fila mínima para no violar la FK.
            if (! DB::table('roles')->where('id', 1)->exists()) {
                DB::table('roles')->insert([
                    'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $user = User::factory()->create(['name' => $nombre]);

            // Mismo mecanismo real que AuthController::login() — el claim
            // tenant_id (User::getJWTCustomClaims()) se resuelve con
            // tenant('id') en el momento de generar el token, así que debe
            // generarse DENTRO de tenancy()->run() del tenant dueño real.
            return auth('api')->login($user->fresh());
        });
    }

    public function test_token_del_tenant_correcto_autentica_normalmente(): void
    {
        $tenant = $this->crearTenantConDominio();
        $token = $this->crearUsuarioYToken($tenant, 'Usuario Real Del Tenant');

        // URL absoluta, no Host header: APP_URL (.env) queda fijo a un
        // subdominio de tenant real (agencia-demo) para el dev server — un
        // path relativo en getJson() hereda ESE host siempre, ignorando
        // cualquier header 'Host' que se pase por separado. La única forma
        // real de simular otro subdominio en un test HTTP es con la URL
        // completa.
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("http://{$tenant->id}.sistemafe.test/api/systems");

        $response->assertOk();
    }

    // Reproduce el hallazgo real de la sesión 10-sep-2026: un JWT emitido
    // para el Tenant A, usado contra el subdominio del Tenant B, debe
    // rechazarse — antes del fix, ni siquiera llegaba a compararse (el
    // grupo de rutas no resolvía tenancy en absoluto).
    public function test_token_de_otro_tenant_es_rechazado(): void
    {
        $tenantA = $this->crearTenantConDominio();
        $tenantB = $this->crearTenantConDominio();

        $tokenDeA = $this->crearUsuarioYToken($tenantA, 'Usuario Del Tenant A');

        $response = $this->withHeaders(['Authorization' => "Bearer {$tokenDeA}"])
            ->getJson("http://{$tenantB->id}.sistemafe.test/api/systems");

        $response->assertStatus(403);
    }

    public function test_ruta_central_ahora_exige_el_pipeline_completo_de_tenancy(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/systems' && in_array('GET', $r->methods(), true)
        );

        $middleware = $route->gatherMiddleware();

        $this->assertContains('tenant', $middleware);
        $this->assertContains('tenant.token', $middleware);
        $this->assertContains('auth:api', $middleware);
    }
}
