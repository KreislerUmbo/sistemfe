<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\AgenciaViajes\Aerolinea;
use App\Services\AgenciaViajes\NormalizadorAerolineas;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// T-016 — Corrección de nombres de aerolínea ya guardados (CA4, CA5). Postgres real; se usa una
// tabla temporal con la misma forma (id + nombre) en vez de las tablas de cotización, que
// exigen media docena de FK. Las columnas reales se declaran en NormalizadorAerolineas::COLUMNAS.
class NormalizarAerolineasTest extends TestCase
{
    private NormalizadorAerolineas $normalizador;

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

        if (! Schema::connection('central')->hasTable('aerolineas')) {
            Artisan::call('migrate', [
                '--path' => ['database/migrations/2026_11_09_100000_create_aerolineas_table.php'],
                '--force' => true,
            ]);
        }

        DB::beginTransaction();
        DB::connection('central')->beginTransaction();

        // Las tablas se crean dentro de la transacción: Postgres las deshace al final.
        (require base_path('database/migrations/tenant/verticals/agencia-viajes/2026_11_09_120000_create_ajustes_aerolinea_table.php'))->up();
        Schema::create('tmp_vuelos', function (Blueprint $t) {
            $t->id();
            $t->string('aerolinea')->nullable();
        });

        $this->normalizador = new class extends NormalizadorAerolineas {
            protected function columnas(): array
            {
                return ['tmp_vuelos' => 'aerolinea'];
            }
        };
    }

    protected function tearDown(): void
    {
        Aerolinea::flushEventListeners();
        DB::connection('central')->rollBack();
        DB::rollBack();
        parent::tearDown();
    }

    private function valores(): array
    {
        return DB::table('tmp_vuelos')->orderBy('id')->pluck('aerolinea')->all();
    }

    private function sembrar(array $valores): void
    {
        foreach ($valores as $v) {
            DB::table('tmp_vuelos')->insert(['aerolinea' => $v]);
        }
    }

    // CA4 — simular lista y no escribe.
    public function test_simular_lista_los_cambios_sin_escribir(): void
    {
        $this->sembrar(['latam', 'Latam', 'LATAM']);

        $r = $this->normalizador->simular();

        $this->assertCount(2, $r['cambios']);
        $this->assertSame(['LATAM', 'LATAM'], array_column($r['cambios'], 'despues'));
        $this->assertSame(['latam', 'Latam', 'LATAM'], $this->valores());
        $this->assertSame(0, DB::table('ajustes_aerolinea')->count());
    }

    // CA4 — aplicar corrige y registra; revertir deja todo como estaba.
    public function test_aplicar_corrige_y_revertir_deja_como_estaba(): void
    {
        $this->sembrar(['latam', ' Sky airline ', 'LATAM']);

        $this->normalizador->aplicar();
        $this->assertSame(['LATAM', 'Sky Airline', 'LATAM'], $this->valores());
        $this->assertSame(2, DB::table('ajustes_aerolinea')->count());

        $r = $this->normalizador->revertir();
        $this->assertSame(2, $r['revertidos']);
        $this->assertSame(['latam', ' Sky airline ', 'LATAM'], $this->valores());

        // Revertir dos veces no repite nada.
        $this->assertSame(0, $this->normalizador->revertir()['revertidos']);
    }

    // CA4 — una aerolínea unida se corrige a la de destino.
    public function test_una_unida_se_corrige_a_la_de_destino(): void
    {
        $destino = Aerolinea::create(['nombre' => 'Zeta Air', 'nombre_normalizado' => 'zeta air']);
        Aerolinea::create(['nombre' => 'Zetta', 'nombre_normalizado' => 'zetta', 'activa' => false, 'unida_a_id' => $destino->id]);
        $this->sembrar(['zetta']);

        $this->normalizador->aplicar();

        $this->assertSame(['Zeta Air'], $this->valores());
    }

    // CA5 — lo que no coincide no se toca y se lista.
    public function test_lo_que_no_coincide_no_se_toca_y_se_lista(): void
    {
        $this->sembrar(['Aerolínea Inventada', 'Aerolínea Inventada', null, '']);

        $r = $this->normalizador->aplicar();

        $this->assertSame([], $r['cambios']);
        $this->assertSame(['Aerolínea Inventada' => 2], $r['sin_coincidencia']);
        $this->assertSame(['Aerolínea Inventada', 'Aerolínea Inventada', null, ''], $this->valores());
    }

    // Revertir no pisa lo que alguien editó a mano después de la corrección.
    public function test_revertir_no_pisa_una_edicion_posterior(): void
    {
        $this->sembrar(['latam']);
        $this->normalizador->aplicar();
        DB::table('tmp_vuelos')->update(['aerolinea' => 'Sky Airline']);

        $r = $this->normalizador->revertir();

        $this->assertSame(['revertidos' => 0, 'omitidos' => 1], $r);
        $this->assertSame(['Sky Airline'], $this->valores());
    }

    // Las columnas reales declaradas existen en el esquema de los tenants de Agencia.
    public function test_las_columnas_declaradas_existen_en_las_migraciones(): void
    {
        $migraciones = '';
        foreach (glob(base_path('database/migrations/tenant/verticals/agencia-viajes/*.php')) as $f) {
            $migraciones .= file_get_contents($f);
        }
        foreach (NormalizadorAerolineas::COLUMNAS as $tabla => $columna) {
            $this->assertStringContainsString("'{$tabla}'", $migraciones);
            $this->assertStringContainsString("'{$columna}'", $migraciones);
        }
    }
}
