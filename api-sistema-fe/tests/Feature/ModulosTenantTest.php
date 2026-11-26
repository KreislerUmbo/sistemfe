<?php

namespace Tests\Feature;

use App\Http\Controllers\Central\TenantModuloController;
use App\Http\Middleware\EnsureModuloActivo;
use App\Models\Central\CentralAuditLog;
use App\Models\Central\MenuItem;
use App\Models\Central\Modulo;
use App\Models\Central\TenantModuloOverride;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MenuResolver;
use App\Services\ModulosTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-135 (Parte A, CA1) — módulos activables por tenant. Tenant descartable con base propia en
// Postgres real y cache 'database' (como en producción): así se comprueba que el interruptor del
// panel central limpia la caché que vive en la base del tenant.
class ModulosTenantTest extends TestCase
{
    private Tenant $tenant;

    private int $userId;

    /** @var list<int> */
    private array $menuIds = [];

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
            'cache.default' => 'database',
        ]);
        DB::purge('pgsql');
        DB::purge('central');

        $id = 'test-mod-'.Str::lower(Str::random(8));
        $this->tenant = Tenant::create(['id' => $id, 'ruc' => $id, 'razon_social' => $id, 'giro' => 'retail']);

        $this->userId = $this->peticionDelTenant(function () {
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => 't135.ver']);
            $user = User::factory()->create();
            $user->givePermissionTo('t135.ver');

            return $user->id;
        });

        $moduloId = Modulo::where('codigo', 'compras')->value('id');
        $grupo = MenuItem::create(['codigo' => "$id.grupo", 'tipo' => 'grupo', 'label' => 'Grupo T135', 'orden' => 999, 'modulo_id' => $moduloId]);
        // El hijo no lleva modulo_id: se cae con su grupo.
        $hijo = MenuItem::create(['codigo' => "$id.hijo", 'tipo' => 'enlace', 'label' => 'Hijo T135', 'ruta' => 'compras.index',
            'permiso_requerido' => 't135.ver', 'parent_id' => $grupo->id, 'orden' => 1]);
        $this->menuIds = [$hijo->id, $grupo->id];
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        MenuItem::whereIn('id', $this->menuIds)->delete();
        CentralAuditLog::where('auditable_id', $this->tenant->id)->delete();

        $tenant = $this->tenant->fresh();
        if ($tenant->database()->manager()->databaseExists($tenant->database()->getName())) {
            $tenant->database()->manager()->deleteDatabase($tenant);
        }
        $tenant->delete();

        parent::tearDown();
    }

    // Cada "petición" arranca sin el store de caché en memoria, como en producción.
    private function peticionDelTenant(callable $accion): mixed
    {
        app('cache')->forgetDriver();
        try {
            return $this->tenant->run($accion);
        } finally {
            app('cache')->forgetDriver();
        }
    }

    private function menuMuestraElModulo(): bool
    {
        return $this->peticionDelTenant(function () {
            $arbol = app(MenuResolver::class)->paraUsuario(User::findOrFail($this->userId), tenant());

            return in_array("{$this->tenant->id}.grupo", array_column($arbol, 'codigo'), true);
        });
    }

    private function pasaElMiddleware(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->peticionDelTenant(fn () => app(EnsureModuloActivo::class)->handle(
            new Request, fn () => response()->json(['ok' => true]), 'compras'
        ));
    }

    private function interruptor(bool $habilitado): array
    {
        app('cache')->forgetDriver();

        return app(TenantModuloController::class)
            ->update(new Request(['habilitado' => $habilitado]), $this->tenant->id, 'compras')
            ->getData(true)['modulos'];
    }

    public function test_ca1_apagado_no_hay_menu_ni_api_y_el_interruptor_los_abre_y_cierra_al_instante(): void
    {
        // Nace apagado; con el permiso del ítem y todo, no aparece.
        $this->assertFalse($this->menuMuestraElModulo());
        $bloqueado = $this->pasaElMiddleware();
        $this->assertSame(403, $bloqueado->getStatusCode());
        $this->assertSame('Este módulo no está habilitado', $bloqueado->getData(true)['message']);

        $modulos = $this->interruptor(true);

        $this->assertTrue(collect($modulos)->firstWhere('codigo', 'compras')['habilitado']);
        // Sin volver a iniciar sesión: la caché del menú y la de módulos se limpiaron.
        $this->assertTrue($this->menuMuestraElModulo());
        $this->assertSame(200, $this->pasaElMiddleware()->getStatusCode());

        $this->interruptor(false);

        $this->assertFalse($this->menuMuestraElModulo());
        $this->assertSame(403, $this->pasaElMiddleware()->getStatusCode());

        $auditoria = CentralAuditLog::where('action', 'tenant.modulo_updated')
            ->where('auditable_id', $this->tenant->id)->orderBy('id')->get();
        $this->assertCount(2, $auditoria);
        $this->assertEquals(['modulo' => 'compras', 'valor_anterior' => false, 'valor_nuevo' => true], $auditoria[0]->payload);
        $this->assertEquals(['modulo' => 'compras', 'valor_anterior' => true, 'valor_nuevo' => false], $auditoria[1]->payload);
        $this->assertSame(1, TenantModuloOverride::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_el_modulo_de_un_tenant_no_se_enciende_en_otro_y_uno_inexistente_da_404(): void
    {
        $this->interruptor(true);

        $otro = Tenant::make(['id' => 'test-mod-otro', 'giro' => 'retail']);
        $this->assertFalse(app(ModulosTenant::class)->activo($otro, 'compras'));

        try {
            app(TenantModuloController::class)->update(new Request(['habilitado' => true]), $this->tenant->id, 'no-existe');
            $this->fail('Debió responder 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
