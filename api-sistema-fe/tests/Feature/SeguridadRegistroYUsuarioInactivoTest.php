<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// Auditoría de seguridad 08-oct-2026, hallazgos 1 y 2:
// 1. POST auth/register era público y creaba un usuario del panel con token
//    válido en cualquier tenant (lectura de ventas/clientes/productos).
// 2. Un usuario desactivado (users.state = 2) podía iniciar sesión, y un token
//    emitido antes de desactivarlo seguía valiendo.
//
// Mismo patrón que AdminPortalCentralRoutesTenancyTest: tenant físico real y
// descartable, requests HTTP con URL absoluta del subdominio.
class SeguridadRegistroYUsuarioInactivoTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        // Los requests HTTP dejan la tenancy iniciada y la conexión 'tenant' abierta;
        // sin cerrarla, Postgres no deja borrar la base ("está siendo utilizada").
        tenancy()->end();
        DB::purge('tenant');

        $tenant = $this->tenant?->fresh();
        if ($tenant) {
            if ($tenant->database()->manager()->databaseExists($tenant->database()->getName())) {
                $tenant->database()->manager()->deleteDatabase($tenant);
            }
            $tenant->delete();
        }

        parent::tearDown();
    }

    private function crearTenant(): Tenant
    {
        $id = 'test-seg-' . Str::lower(Str::random(8));
        $this->tenant = Tenant::create(['id' => $id, 'ruc' => $id, 'razon_social' => "Tenant de prueba {$id}"]);
        $this->tenant->domains()->create(['domain' => $id]);

        return $this->tenant;
    }

    private function crearUsuario(Tenant $tenant, int $state): User
    {
        return $tenant->run(function () use ($state) {
            if (! DB::table('roles')->where('id', 1)->exists()) {
                DB::table('roles')->insert([
                    'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return User::factory()->create(['state' => $state, 'role_id' => 1]);
        });
    }

    private function url(Tenant $tenant, string $path): string
    {
        return "http://{$tenant->id}.sistemafe.test/api/{$path}";
    }

    public function test_registro_publico_ya_no_existe_ni_crea_usuarios(): void
    {
        $tenant = $this->crearTenant();

        $response = $this->postJson($this->url($tenant, 'auth/register'), [
            'name' => 'Intruso',
            'email' => 'intruso@example.com',
            'password' => 'password123',
        ]);

        $this->assertContains($response->status(), [404, 405]);
        $this->assertFalse(
            $tenant->run(fn () => User::where('email', 'intruso@example.com')->exists()),
            'auth/register no debe crear usuarios del panel.'
        );
    }

    public function test_usuario_activo_inicia_sesion_e_inactivo_es_rechazado(): void
    {
        $tenant = $this->crearTenant();
        $activo = $this->crearUsuario($tenant, 1);
        $inactivo = $this->crearUsuario($tenant, User::STATE_INACTIVO);

        $this->postJson($this->url($tenant, 'auth/login'), ['email' => $activo->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['access_token']);

        $this->postJson($this->url($tenant, 'auth/login'), ['email' => $inactivo->email, 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonMissingPath('access_token');
    }

    public function test_token_emitido_antes_de_desactivar_deja_de_servir(): void
    {
        $tenant = $this->crearTenant();
        $user = $this->crearUsuario($tenant, 1);
        $token = $tenant->run(fn () => auth('api')->login($user->fresh()));

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson($this->url($tenant, 'me/menu'))
            ->assertOk();

        $tenant->run(fn () => User::whereKey($user->id)->update(['state' => User::STATE_INACTIVO]));
        // Cada request de verdad arranca con el guard limpio; en el test la app se
        // reutiliza, así que se olvida el usuario ya resuelto del request anterior.
        auth('api')->forgetUser();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson($this->url($tenant, 'me/menu'))
            ->assertStatus(401);

        auth('api')->forgetUser();
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson($this->url($tenant, 'auth/refresh'))
            ->assertStatus(401);
    }

    // Guardrail: toda ruta autenticada del panel (auth:api) debe cortar a un
    // usuario desactivado — salvo logout, que solo invalida el token.
    public function test_toda_ruta_con_auth_api_exige_usuario_activo(): void
    {
        $sinGuard = collect(Route::getRoutes())
            ->filter(fn ($r) => in_array('auth:api', $r->gatherMiddleware(), true))
            ->reject(fn ($r) => $r->uri() === 'api/auth/logout')
            ->reject(fn ($r) => in_array('user.active', $r->gatherMiddleware(), true))
            ->map(fn ($r) => implode('|', $r->methods()) . ' ' . $r->uri())
            ->values()
            ->all();

        $this->assertSame([], $sinGuard, 'Rutas con auth:api sin user.active.');
    }
}
