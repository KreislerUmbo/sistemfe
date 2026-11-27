<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

// T-132 — `proveedores` es del sistema base: la tienen todos los giros. Mismo patrón que
// MigrateVerticalesPendientesTest: tenants descartables con base física propia en Postgres
// real, 'central' redirigida a sistemafe_test_migrations, y se borran siempre en tearDown().
//
// El provisioning se reproduce con sus dos pasos reales de esquema (Tenant::create() corre
// core/ y migrarVertical() el vertical del giro), sin los seeders de roles y menú.
class ProveedoresTablaBaseTest extends TestCase
{
    private const MIGRACION_CORE = '2026_11_24_200000_create_proveedores_table_core';

    // T-134: altera la tabla desde core/; en una agencia anterior corre después del vertical.
    private const MIGRACION_COLUMNAS = '2026_11_26_100000_add_direcciones_y_no_domiciliado_to_proveedores';

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

    // Solo core/ (lo que deja Tenant::create()).
    private function crearTenant(string $giro): Tenant
    {
        $id = 'test-prov-'.Str::lower(Str::random(8));

        $tenant = Tenant::create([
            'id' => $id,
            'ruc' => $id,
            'razon_social' => "Tenant de prueba {$id}",
            'giro' => $giro,
        ]);

        $this->tenantsCreados[] = $tenant;

        return $tenant;
    }

    private function provisionar(string $giro): Tenant
    {
        $tenant = $this->crearTenant($giro);

        (new ReflectionMethod(TenantProvisioningService::class, 'migrarVertical'))
            ->invoke(app(TenantProvisioningService::class), $tenant, $giro);

        return $tenant;
    }

    // Una agencia anterior a T-132: la tabla la crea y la altera el vertical, no core/.
    private function agenciaAnterior(): Tenant
    {
        $tenant = $this->crearTenant('agencia_viajes');

        $tenant->run(function () {
            // T-135 y T-154: compras (posterior, con FK a proveedores) y sus líneas no existían todavía.
            Schema::drop('compra_lineas');
            Schema::drop('compras');
            Schema::drop('proveedores');
            DB::table('migrations')->whereIn('migration', [self::MIGRACION_CORE, self::MIGRACION_COLUMNAS])->delete();
        });

        (new ReflectionMethod(TenantProvisioningService::class, 'migrarVertical'))
            ->invoke(app(TenantProvisioningService::class), $tenant, 'agencia_viajes');

        return $tenant;
    }

    /** Columnas (tipo, largo, nulo, default) e índices de proveedores, sin depender del orden. */
    private function esquema(Tenant $tenant): array
    {
        return $tenant->run(function () {
            $columnas = collect(DB::select(
                "select column_name, data_type, character_maximum_length, numeric_precision, numeric_scale, is_nullable, column_default
                 from information_schema.columns where table_schema = current_schema() and table_name = 'proveedores'
                 order by column_name"
            ))->map(fn ($fila) => (array) $fila)->all();

            $indices = collect(DB::select(
                "select indexdef from pg_indexes where schemaname = current_schema() and tablename = 'proveedores' order by indexname"
            ))->pluck('indexdef')->all();

            return ['columnas' => $columnas, 'indices' => $indices];
        });
    }

    private function migracionCore(): object
    {
        return require database_path('migrations/tenant/core/'.self::MIGRACION_CORE.'.php');
    }

    public function test_ca1_tienda_y_creditos_recien_provisionados_tienen_proveedores(): void
    {
        foreach (['retail', 'creditos'] as $giro) {
            $tenant = $this->provisionar($giro);

            $this->assertTrue($tenant->run(fn () => Schema::hasTable('proveedores')), "Falta proveedores en {$giro}");
            $this->assertFalse($tenant->run(fn () => Schema::hasTable('proveedor_servicios')), "{$giro} no debe recibir tablas de Agencia");
        }
    }

    public function test_ca2_agencia_nueva_queda_con_el_mismo_esquema_que_una_anterior(): void
    {
        $nueva = $this->provisionar('agencia_viajes');
        $anterior = $this->agenciaAnterior();
        $anterior->run(fn () => (require database_path('migrations/tenant/core/'.self::MIGRACION_COLUMNAS.'.php'))->up());

        // El vertical corrió completo en las dos.
        foreach ([$nueva, $anterior] as $tenant) {
            $this->assertTrue($tenant->run(fn () => Schema::hasTable('proveedor_servicios')));
            $this->assertTrue($tenant->run(fn () => Schema::hasColumn('guias', 'es_referencial')));
            $this->assertFalse($tenant->run(fn () => Schema::hasColumn('salidas_mayorista', 'fecha_retorno')));
        }

        $this->assertSame($this->esquema($anterior), $this->esquema($nueva));
        $this->assertNotEmpty($this->esquema($nueva)['columnas']);
        $this->assertFalse($nueva->run(fn () => Schema::hasColumn('proveedores', 'pais_id')));
    }

    public function test_ca3_migrate_verticales_dos_veces_no_falla_ni_toca_los_datos_de_una_agencia_anterior(): void
    {
        $anterior = $this->agenciaAnterior();
        $anterior->run(fn () => DB::table('proveedores')->insert(['razon_social' => 'Hotel Anterior SAC']));

        // agenciaAnterior() dejó pendiente la migración de core/: la primera corrida la aplica.
        $this->assertSame(0, Artisan::call('tenants:migrate-verticales'));
        $this->assertSame(0, Artisan::call('tenants:migrate-verticales'));

        $anterior->run(function () {
            $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRACION_CORE)->count());
            $this->assertSame(['Hotel Anterior SAC'], DB::table('proveedores')->pluck('razon_social')->all());
        });
    }

    public function test_ca4_el_down_no_borra_la_tabla_en_una_agencia(): void
    {
        $agencia = $this->provisionar('agencia_viajes');
        $retail = $this->provisionar('retail');

        $agencia->run(fn () => $this->migracionCore()->down());
        // Un rollback va en orden inverso: lo que apunta a proveedores (T-135) sale antes.
        $retail->run(fn () => Schema::drop('compra_lineas'));
        $retail->run(fn () => Schema::drop('compras'));
        $retail->run(fn () => $this->migracionCore()->down());

        $this->assertTrue($agencia->run(fn () => Schema::hasTable('proveedores')));
        $this->assertFalse($retail->run(fn () => Schema::hasTable('proveedores')));
    }
}
