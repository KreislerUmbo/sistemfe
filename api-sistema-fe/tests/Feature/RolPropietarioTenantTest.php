<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Central\TenantAdminController;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Roles\CuentaSoporte;
use App\Services\Roles\RolPropietario;
use App\Services\TenantProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * T-081 — alta de tenant (CA1), roles:sync-propietario (CA2), roles:asignar-propietario (CA6) y
 * restablecer contraseña desde el panel (CA7). Provisiona tenants físicos descartables (mismo
 * patrón que AgenciaViajesRolesSeederTest): es la única forma real de probar el alta completa.
 */
final class RolPropietarioTenantTest extends TestCase
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

    private function provisionar(string $giro): Tenant
    {
        $domain = 'test-t081-' . Str::lower(Str::random(8));
        $tenant = app(TenantProvisioningService::class)->provision([
            'ruc' => $domain,
            'razon_social' => 'Tenant T-081 SAC',
            'razon_social_comercial' => 'Tenant T-081',
            'domain' => $domain,
            'admin_name' => 'Dueño',
            'admin_email' => 'dueno@x.com',
            'admin_password' => 'password123',
            'giro' => $giro,
            'facturacion_habilitada' => false,
        ]);
        $this->tenantsCreados[] = $tenant;

        return $tenant;
    }

    /** Deja el tenant como antes de T-081: dueño Super-Admin, sin cuenta de soporte. */
    private function comoAntesDeT081(Tenant $tenant): void
    {
        $tenant->run(function () {
            User::withTrashed()->where('email', CuentaSoporte::EMAIL)->forceDelete();
            $super = Role::where('name', 'Super-Admin')->first();
            $duenio = User::where('email', 'dueno@x.com')->first();
            $duenio->syncRoles([$super]);
            $duenio->forceFill(['role_id' => $super->id])->save();
        });
    }

    // CA1
    public function test_el_alta_de_cada_giro_crea_al_duenio_propietario_y_la_cuenta_de_soporte(): void
    {
        foreach (['retail', 'agencia_viajes', 'creditos'] as $giro) {
            $tenant = $this->provisionar($giro);

            $tenant->run(function () use ($giro) {
                $duenio = User::where('email', 'dueno@x.com')->first();
                $this->assertTrue($duenio->hasRole(RolPropietario::NOMBRE), $giro);
                $this->assertFalse($duenio->hasRole('Super-Admin'), $giro);
                $this->assertSame(RolPropietario::NOMBRE, $duenio->role->name, "{$giro}: role_id apunta al Propietario");
                $this->assertSame(Permission::where('guard_name', 'api')->count(), $duenio->getAllPermissions()->count(), "{$giro}: todos los permisos");

                $soporte = User::where('email', CuentaSoporte::EMAIL)->first();
                $this->assertNotNull($soporte, $giro);
                $this->assertSame(CuentaSoporte::NOMBRE, $soporte->name);
                $this->assertTrue($soporte->hasRole('Super-Admin'), $giro);
                $this->assertSame('Super-Admin', $soporte->role->name);
            });
        }
    }

    // CA2 (el comando; la lógica de sincronizar está en RolPropietarioTest)
    public function test_sync_propietario_le_da_el_permiso_nuevo_y_correrlo_dos_veces_no_cambia_nada(): void
    {
        $tenant = $this->provisionar('retail');
        $tenant->run(fn () => Permission::create(['guard_name' => 'api', 'name' => 't081.funcion_nueva']));

        $this->assertSame(0, Artisan::call('roles:sync-propietario', ['--tenant' => [$tenant->id]]));
        $this->assertMatchesRegularExpression("/{$tenant->id}\s*\|\s*1\s*\|\s*0/", Artisan::output());
        $tenant->run(fn () => $this->assertTrue(Role::where('name', RolPropietario::NOMBRE)->first()->hasPermissionTo('t081.funcion_nueva')));

        $this->assertSame(0, Artisan::call('roles:sync-propietario', ['--tenant' => [$tenant->id]]));
        $this->assertMatchesRegularExpression("/{$tenant->id}\s*\|\s*0\s*\|\s*0/", Artisan::output());

        $this->assertSame(1, Artisan::call('roles:sync-propietario', ['--tenant' => ['no-existe-t081']]));
    }

    // CA6
    public function test_asignar_propietario_simula_migra_y_es_idempotente(): void
    {
        $tenant = $this->provisionar('retail');
        $this->comoAntesDeT081($tenant);

        $this->assertSame(0, Artisan::call('roles:asignar-propietario', ['tenant' => $tenant->id, 'email' => 'dueno@x.com', '--simular' => true]));
        $salida = Artisan::output();
        $this->assertStringContainsString('[simulación]', $salida);
        $this->assertStringContainsString('quitar a dueno@x.com el rol Super-Admin', $salida);
        $this->assertStringContainsString('crear la cuenta de soporte', $salida);
        $tenant->run(function () {
            $this->assertTrue(User::where('email', 'dueno@x.com')->first()->hasRole('Super-Admin'), 'Simular no escribe.');
            $this->assertFalse(User::withTrashed()->where('email', CuentaSoporte::EMAIL)->exists());
        });

        $this->assertSame(0, Artisan::call('roles:asignar-propietario', ['tenant' => $tenant->id, 'email' => 'dueno@x.com']));
        $tenant->run(function () {
            $duenio = User::where('email', 'dueno@x.com')->first();
            $this->assertTrue($duenio->hasRole(RolPropietario::NOMBRE));
            $this->assertFalse($duenio->hasRole('Super-Admin'));
            $this->assertSame(RolPropietario::NOMBRE, $duenio->role->name);
            $this->assertTrue(User::where('email', CuentaSoporte::EMAIL)->first()->hasRole('Super-Admin'));
        });

        $this->assertSame(0, Artisan::call('roles:asignar-propietario', ['tenant' => $tenant->id, 'email' => 'dueno@x.com']));
        $this->assertStringContainsString('Nada que hacer', Artisan::output());

        $this->assertSame(1, Artisan::call('roles:asignar-propietario', ['tenant' => $tenant->id, 'email' => 'nadie@x.com']));
        $tenant->run(fn () => User::factory()->create(['email' => 'borrado@x.com'])->delete());
        $this->assertSame(1, Artisan::call('roles:asignar-propietario', ['tenant' => $tenant->id, 'email' => 'borrado@x.com']));
        $this->assertSame(1, Artisan::call('roles:asignar-propietario', ['tenant' => 'no-existe-t081', 'email' => 'dueno@x.com']));
    }

    // CA7
    public function test_restablecer_contrasena_va_al_propietario_al_soporte_o_al_super_admin_si_no_hay_propietario(): void
    {
        $tenant = $this->provisionar('retail');
        $panel = app(TenantAdminController::class);
        $restablecer = fn (array $datos) => $panel->resetAdminPassword(new Request($datos), $tenant->id);
        $claveDe = fn (string $email, string $clave) => $tenant->run(fn () => Hash::check($clave, User::where('email', $email)->first()->password));

        $restablecer(['new_password' => 'clave-duenio-1']);
        $this->assertTrue($claveDe('dueno@x.com', 'clave-duenio-1'), 'Sin admin_email, al Propietario.');

        $restablecer(['new_password' => 'clave-soporte-1', 'admin_email' => CuentaSoporte::EMAIL]);
        $this->assertTrue($claveDe(CuentaSoporte::EMAIL, 'clave-soporte-1'), 'Con el correo de soporte, a la cuenta de soporte.');
        $this->assertTrue($claveDe('dueno@x.com', 'clave-duenio-1'));

        // Un usuario que no es Propietario ni Super-Admin no es candidato.
        $tenant->run(fn () => User::factory()->create(['email' => 'cajero@x.com']));
        try {
            $restablecer(['new_password' => 'clave-cajero-1', 'admin_email' => 'cajero@x.com']);
            $this->fail('Debió responder 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Tenant aún sin migrar (dueño Super-Admin, sin soporte): al Super-Admin, como antes.
        $this->comoAntesDeT081($tenant);
        $restablecer(['new_password' => 'clave-super-1']);
        $this->assertTrue($claveDe('dueno@x.com', 'clave-super-1'));
    }
}
