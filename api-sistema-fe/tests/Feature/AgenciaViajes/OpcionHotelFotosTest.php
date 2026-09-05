<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\OpcionHotelController;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Services\AgenciaViajes\FotoUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Pedido del usuario (05-sep-2026): hasta 3 fotos por hotel al agregarlo/
// editarlo en el comparador de mayoristas (Internacional). Tope propio de 3
// (no el genérico de 10 que comparten destinos/paquetes) + mismo patrón de
// eliminar-una-foto ya usado ahí. Mismo estilo de test que
// DestinoAtractivoFotosTest (controller invocado directamente, Postgres real
// vía sistemafe_test_migrations, Storage::fake('public') aísla los archivos).
class OpcionHotelFotosTest extends TestCase
{
    private OpcionHotelController $controller;

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

        Storage::fake('public');

        $this->controller = new OpcionHotelController(new FotoUploadService());
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function requestConFotos(array $fotos): Request
    {
        return new Request([], [], [], [], ['fotos' => $fotos], ['REQUEST_METHOD' => 'POST']);
    }

    private function requestConDatos(array $datos): Request
    {
        return new Request([], $datos, [], [], [], ['REQUEST_METHOD' => 'POST']);
    }

    private function crearHotel(array $fotos = []): OpcionHotel
    {
        return OpcionHotel::create(['nombre_hotel' => 'Hotel Test Fotos', 'moneda' => 'USD', 'fotos' => $fotos]);
    }

    public function test_agrega_fotos_y_las_resuelve_a_url_completa(): void
    {
        $hotel = $this->crearHotel();
        $foto = UploadedFile::fake()->image('foto.jpg', 100, 100);

        $response = $this->controller->agregarFotos($this->requestConFotos([$foto]), (string) $hotel->id);
        $data = $response->getData(true);

        $this->assertSame(200, $data['code']);
        $this->assertCount(1, $hotel->fresh()->fotos);
        $this->assertStringContainsString('/tenancy/assets/opciones-hotel/', $data['opcion_hotel']['fotos'][0]);
    }

    public function test_rechaza_cuarta_foto_cuando_ya_hay_tres(): void
    {
        $hotel = $this->crearHotel(['opciones-hotel/a.jpg', 'opciones-hotel/b.jpg', 'opciones-hotel/c.jpg']);
        $nueva = UploadedFile::fake()->image('cuarta.jpg', 100, 100);

        $response = $this->controller->agregarFotos($this->requestConFotos([$nueva]), (string) $hotel->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('3 fotos', $response->getData(true)['message']);
        $this->assertCount(3, $hotel->fresh()->fotos);
    }

    public function test_dos_fotos_nuevas_sobre_una_existente_tambien_rechaza_por_superar_el_maximo(): void
    {
        $hotel = $this->crearHotel(['opciones-hotel/a.jpg']);
        $fotos = [UploadedFile::fake()->image('b.jpg', 100, 100), UploadedFile::fake()->image('c.jpg', 100, 100), UploadedFile::fake()->image('d.jpg', 100, 100)];

        $response = $this->controller->agregarFotos($this->requestConFotos($fotos), (string) $hotel->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $hotel->fresh()->fotos);
    }

    public function test_eliminar_foto_borra_archivo_fisico_y_lo_saca_del_array(): void
    {
        $archivo = UploadedFile::fake()->image('foto.jpg', 100, 100);
        $hotel = $this->crearHotel();
        $creado = $this->controller->agregarFotos($this->requestConFotos([$archivo]), (string) $hotel->id)->getData(true);

        $pathRelativo = $hotel->fresh()->fotos[0];
        $urlResuelta = $creado['opcion_hotel']['fotos'][0];
        $this->assertTrue(Storage::disk('public')->exists($pathRelativo));

        $response = $this->controller->eliminarFoto($this->requestConDatos(['path' => $urlResuelta]), (string) $hotel->id);
        $data = $response->getData(true);

        $this->assertSame(200, $data['code']);
        $this->assertFalse(Storage::disk('public')->exists($pathRelativo));
        $this->assertEmpty($hotel->fresh()->fotos);
    }

    public function test_eliminar_foto_rechaza_path_que_no_pertenece_al_hotel(): void
    {
        $hotel = $this->crearHotel(['opciones-hotel/real.jpg']);

        $response = $this->controller->eliminarFoto($this->requestConDatos(['path' => 'opciones-hotel/otro.jpg']), (string) $hotel->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $hotel->fresh()->fotos);
    }
}
