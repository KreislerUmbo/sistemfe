<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\PaquetePlantillaController;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Services\AgenciaViajes\CodigoGeneradorService;
use App\Services\AgenciaViajes\ComboExplosionService;
use App\Services\AgenciaViajes\ComboValidationService;
use App\Services\AgenciaViajes\FotoUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Mejora del PDF de cotización (plan-mejora-pdf-cotizacion-cliente.md §4.5)
// — portada (una sola) + destacadas (hasta 4) para la galería del
// itinerario, ambas referencian paths ya existentes en `fotos` (no suben
// archivos nuevos). Mismo estilo de test que OpcionHotelFotosTest
// (controller invocado directamente, Postgres real).
class PaquetePlantillaFotosPdfTest extends TestCase
{
    private PaquetePlantillaController $controller;

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

        $this->controller = new PaquetePlantillaController(
            app(ComboValidationService::class),
            app(ComboExplosionService::class),
            app(FotoUploadService::class),
            app(CodigoGeneradorService::class),
        );
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearPaquete(array $fotos): PaquetePlantilla
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        return PaquetePlantilla::create([
            'categoria' => 'nacional',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Tour Test Fotos PDF',
            'destino_atractivo_id' => $destino->id,
            'duracion_horas' => 8,
            'fotos' => $fotos,
        ]);
    }

    private function requestConDatos(array $datos): Request
    {
        return new Request([], $datos, [], [], [], ['REQUEST_METHOD' => 'PUT']);
    }

    public function test_marca_portada_y_destacadas_correctamente(): void
    {
        $paquete = $this->crearPaquete(['tours/a.jpg', 'tours/b.jpg', 'tours/c.jpg']);

        $response = $this->controller->actualizarFotosPdf($this->requestConDatos([
            'foto_portada' => 'tours/a.jpg',
            'fotos_destacadas_pdf' => ['tours/b.jpg', 'tours/c.jpg'],
        ]), (string) $paquete->id);
        $data = $response->getData(true);

        $this->assertSame(200, $data['code']);
        $fresco = $paquete->fresh();
        $this->assertSame('tours/a.jpg', $fresco->foto_portada);
        $this->assertSame(['tours/b.jpg', 'tours/c.jpg'], $fresco->fotos_destacadas_pdf);
    }

    public function test_rechaza_portada_que_no_pertenece_al_paquete(): void
    {
        $paquete = $this->crearPaquete(['tours/a.jpg']);

        $response = $this->controller->actualizarFotosPdf($this->requestConDatos([
            'foto_portada' => 'tours/inexistente.jpg',
        ]), (string) $paquete->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull($paquete->fresh()->foto_portada);
    }

    public function test_rechaza_destacada_que_no_pertenece_al_paquete(): void
    {
        $paquete = $this->crearPaquete(['tours/a.jpg']);

        $response = $this->controller->actualizarFotosPdf($this->requestConDatos([
            'fotos_destacadas_pdf' => ['tours/a.jpg', 'tours/inexistente.jpg'],
        ]), (string) $paquete->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertEmpty($paquete->fresh()->fotos_destacadas_pdf ?? []);
    }

    public function test_rechaza_mas_de_cuatro_destacadas(): void
    {
        $paquete = $this->crearPaquete(['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg', 'e.jpg']);

        $response = $this->controller->actualizarFotosPdf($this->requestConDatos([
            'fotos_destacadas_pdf' => ['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg', 'e.jpg'],
        ]), (string) $paquete->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_eliminar_foto_portada_limpia_la_referencia(): void
    {
        $paquete = $this->crearPaquete(['tours/a.jpg', 'tours/b.jpg']);
        $paquete->update(['foto_portada' => 'tours/a.jpg', 'fotos_destacadas_pdf' => ['tours/a.jpg', 'tours/b.jpg']]);

        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('tours/a.jpg', 'contenido');

        $response = $this->controller->eliminarFoto(
            new Request([], ['path' => 'tours/a.jpg'], [], [], [], ['REQUEST_METHOD' => 'DELETE']),
            (string) $paquete->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $fresco = $paquete->fresh();
        $this->assertNull($fresco->foto_portada);
        $this->assertSame(['tours/b.jpg'], $fresco->fotos_destacadas_pdf);
    }
}
