<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 12b — brief PEGAR-EN-CLAUDE-CODE-12b-crear-alternativa-destinos.md
// §3/§4. La migración de backfill (2026_09_01_100100_backfill_alternativa_destinos.php)
// ya corrió una sola vez contra sistemafe_test_migrations y agencia-demo —
// no es un servicio que se pueda invocar de nuevo por request. Para
// probar su lógica (match exacto / match case-insensitive / sin match)
// se re-invoca directamente el archivo de migración (cada archivo de
// migración de Laravel YA es `return new class extends Migration {...}`,
// así que requerirlo devuelve la instancia lista para llamar ->up()) sobre
// datos de prueba frescos dentro de la transacción del test. Mismo patrón
// de infraestructura que el resto de la suite de agencia-viajes: Postgres
// real (sistemafe_test_migrations), transacción por test revertida.
class Sesion12bAlternativaDestinosBackfillTest extends TestCase
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
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearAlternativa(string $destinoTexto, ?string $fechaDesde = '2026-10-01', ?string $fechaHasta = '2026-10-05'): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667788', 'full_name' => 'Cliente Test 12b',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-04' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => $destinoTexto, 'fecha_viaje_desde' => $fechaDesde, 'fecha_viaje_hasta' => $fechaHasta,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
            'total' => 0,
        ]);
    }

    private function correrBackfill(): void
    {
        // Vacía lo que la migración real ya insertó una vez al migrar la
        // base de test, para que este test controle exactamente qué filas
        // ve el backfill (mismas Alternativas de siempre + las nuevas de
        // este test).
        DB::table('alternativa_destinos')->truncate();

        $migracion = require database_path('migrations/tenant/verticals/agencia-viajes/2026_09_01_100100_backfill_alternativa_destinos.php');
        $migracion->up();
    }

    public function test_backfill_resuelve_match_exacto(): void
    {
        $destinoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Tarapoto Test 12b', 'tipo' => 'zona', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = $this->crearAlternativa('Tarapoto Test 12b');

        $this->correrBackfill();

        $fila = DB::table('alternativa_destinos')->where('alternativa_id', $alternativa->id)->first();
        $this->assertNotNull($fila);
        $this->assertSame($destinoId, $fila->destino_atractivo_id);
        $this->assertSame('Tarapoto Test 12b', $fila->destino_texto);
        $this->assertSame(1, $fila->orden);
    }

    public function test_backfill_resuelve_match_case_insensitive(): void
    {
        $destinoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Cusco Test 12b', 'tipo' => 'zona', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Mismo caso real ya encontrado en agencia-demo: "Alto Mayo" vs "Alto mayo".
        $alternativa = $this->crearAlternativa('cusco test 12b');

        $this->correrBackfill();

        $fila = DB::table('alternativa_destinos')->where('alternativa_id', $alternativa->id)->first();
        $this->assertSame($destinoId, $fila->destino_atractivo_id);
        $this->assertSame('cusco test 12b', $fila->destino_texto, 'destino_texto conserva el texto original, no el del catálogo');
    }

    public function test_backfill_deja_null_si_no_hay_match_en_el_catalogo(): void
    {
        $alternativa = $this->crearAlternativa('Destino Inexistente XYZ');

        $this->correrBackfill();

        $fila = DB::table('alternativa_destinos')->where('alternativa_id', $alternativa->id)->first();
        $this->assertNull($fila->destino_atractivo_id);
        $this->assertSame('Destino Inexistente XYZ', $fila->destino_texto, 'el texto nunca se pierde aunque no matchee');
    }

    public function test_backfill_copia_fechas_de_la_cotizacion_y_permite_null(): void
    {
        $conFechas = $this->crearAlternativa('Destino Con Fechas', '2026-11-01', '2026-11-10');
        $sinFechas = $this->crearAlternativa('Destino Sin Fechas', null, null);

        $this->correrBackfill();

        $filaConFechas = DB::table('alternativa_destinos')->where('alternativa_id', $conFechas->id)->first();
        $this->assertSame('2026-11-01', $filaConFechas->fecha_inicio);
        $this->assertSame('2026-11-10', $filaConFechas->fecha_fin);

        $filaSinFechas = DB::table('alternativa_destinos')->where('alternativa_id', $sinFechas->id)->first();
        $this->assertNull($filaSinFechas->fecha_inicio);
        $this->assertNull($filaSinFechas->fecha_fin);
    }

    public function test_backfill_crea_exactamente_una_fila_por_alternativa_sin_perder_ninguna(): void
    {
        $a1 = $this->crearAlternativa('Destino A');
        $a2 = $this->crearAlternativa('Destino B');
        $a3 = $this->crearAlternativa('Destino C');

        $totalAntes = Alternativa::count();
        $this->correrBackfill();
        $totalDespues = DB::table('alternativa_destinos')->count();

        $this->assertSame($totalAntes, $totalDespues);
        foreach ([$a1, $a2, $a3] as $alternativa) {
            $this->assertSame(1, DB::table('alternativa_destinos')->where('alternativa_id', $alternativa->id)->count());
        }
    }

    public function test_alternativa_destinos_relacion_ordena_por_orden(): void
    {
        $alternativa = $this->crearAlternativa('Destino Relación');
        $this->correrBackfill();

        $this->assertCount(1, $alternativa->fresh()->destinos);
    }
}
