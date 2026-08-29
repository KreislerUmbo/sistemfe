<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ServicioController;
use App\Models\AgenciaViajes\Servicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 29-ago-2026 — diagnóstico UX/técnico del flujo de servicios en Destinos:
// ServicioController::store()/update() no tenían ninguna protección contra
// duplicados (ni unique, ni case-insensitive, ni trim) — "Traslado" /
// "traslado" / "Traslado " (espacio) se creaban como 3 filas distintas del
// mismo catálogo compartido. Es la causa real detrás de que la mayoría de
// servicios terminen en "Sin categoría" en el desglose de
// paquetes/detalle.vue. Bloqueo solo en coincidencia EXACTA
// (case-insensitive + trim) — nombres parecidos pero distintos
// ("Traslado ida y vuelta") siguen permitidos.
class ServicioDuplicadoTest extends TestCase
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

    public function test_store_rechaza_nombre_exacto_ya_existente(): void
    {
        Servicio::create(['nombre' => 'Traslado']);

        $respuesta = app(ServicioController::class)->store(new Request(['nombre' => 'Traslado']));

        $this->assertSame(422, $respuesta->getData(true)['code']);
        $this->assertSame(1, Servicio::where('nombre', 'Traslado')->count());
    }

    public function test_store_rechaza_nombre_con_distinta_capitalizacion(): void
    {
        Servicio::create(['nombre' => 'Traslado']);

        $respuesta = app(ServicioController::class)->store(new Request(['nombre' => 'traslado']));

        $this->assertSame(422, $respuesta->getData(true)['code']);
        $this->assertStringContainsString('Ya existe', $respuesta->getData(true)['message']);
    }

    public function test_store_rechaza_nombre_con_espacios_de_mas(): void
    {
        Servicio::create(['nombre' => 'Traslado']);

        $respuesta = app(ServicioController::class)->store(new Request(['nombre' => '  Traslado  ']));

        $this->assertSame(422, $respuesta->getData(true)['code']);
        $this->assertSame(1, Servicio::count());
    }

    public function test_store_permite_nombre_parecido_pero_distinto(): void
    {
        Servicio::create(['nombre' => 'Traslado']);

        $respuesta = app(ServicioController::class)->store(new Request(['nombre' => 'Traslado ida y vuelta']));

        $this->assertSame(200, $respuesta->getData(true)['code']);
        $this->assertSame(2, Servicio::count());
    }

    public function test_update_rechaza_renombrar_a_otro_ya_existente(): void
    {
        Servicio::create(['nombre' => 'Traslado']);
        $otro = Servicio::create(['nombre' => 'City tour']);

        $respuesta = app(ServicioController::class)->update(new Request(['nombre' => 'Traslado']), (string) $otro->id);

        $this->assertSame(422, $respuesta->getData(true)['code']);
        $this->assertSame('City tour', $otro->fresh()->nombre);
    }

    public function test_update_permite_guardar_el_mismo_nombre_propio(): void
    {
        $servicio = Servicio::create(['nombre' => 'Traslado']);

        $respuesta = app(ServicioController::class)->update(new Request(['nombre' => 'Traslado', 'tipo_proveedor_id' => 5]), (string) $servicio->id);

        $this->assertSame(200, $respuesta->getData(true)['code']);
    }
}
