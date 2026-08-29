<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\DestinoAtractivoController;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Servicio;
use App\Services\AgenciaViajes\FotoUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 29-ago-2026 — destino_servicios_count (withCount) agregado a index()
// para el filtro "sin servicios asociados" de destinos/index.vue. Cubre
// los 3 niveles del árbol (raíz + hijos + hijos.hijos), que se agregan
// con withCount() por separado en cada eager-load closure.
class DestinoAtractivoServiciosCountTest extends TestCase
{
    private DestinoAtractivoController $controller;

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

        $this->controller = new DestinoAtractivoController(new FotoUploadService());
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_arbol_incluye_conteo_de_servicios_en_los_3_niveles(): void
    {
        $zona = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $lugar = DestinoAtractivo::create(['nombre' => 'Rioja', 'tipo' => 'lugar', 'parent_id' => $zona->id]);
        $atractivoConServicio = DestinoAtractivo::create(['nombre' => 'Laguna Azul', 'tipo' => 'atractivo', 'parent_id' => $lugar->id]);
        $atractivoSinServicio = DestinoAtractivo::create(['nombre' => 'Mirador', 'tipo' => 'atractivo', 'parent_id' => $lugar->id]);

        $servicio = Servicio::create(['nombre' => 'Paseo en bote']);
        DestinoServicio::create(['destino_atractivo_id' => $atractivoConServicio->id, 'servicio_id' => $servicio->id]);

        $respuesta = $this->controller->index(new Request());
        $payload = $respuesta->getData(true)['destinos_atractivos'];

        $zonaData = collect($payload)->firstWhere('id', $zona->id);
        $this->assertSame(0, $zonaData['destino_servicios_count'], 'La zona no tiene servicios propios.');

        $lugarData = collect($zonaData['hijos'])->firstWhere('id', $lugar->id);
        $this->assertSame(0, $lugarData['destino_servicios_count']);

        $atractivoConServicioData = collect($lugarData['hijos'])->firstWhere('id', $atractivoConServicio->id);
        $this->assertSame(1, $atractivoConServicioData['destino_servicios_count']);

        $atractivoSinServicioData = collect($lugarData['hijos'])->firstWhere('id', $atractivoSinServicio->id);
        $this->assertSame(0, $atractivoSinServicioData['destino_servicios_count']);
    }
}
