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
// eliminar-una-foto ya usado ahí.
//
// Reescrito en la mejora del PDF de cotización
// (plan-mejora-pdf-cotizacion-cliente.md §4.5): el diseño original (batch de
// fotos sin tipo) cambió a una foto por request con tipo_foto explícito
// (fachada máx. 1, habitación máx. 2) — el PDF necesita esa distinción para
// armar la tira fachada-primero, habitación-después. Mismo estilo de test
// que DestinoAtractivoFotosTest (controller invocado directamente, Postgres
// real vía sistemafe_test_migrations, Storage::fake('public') aísla los
// archivos).
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

    private function requestConFoto(\Illuminate\Http\UploadedFile $foto, string $tipoFoto): Request
    {
        return new Request([], ['tipo_foto' => $tipoFoto], [], [], ['foto' => $foto], ['REQUEST_METHOD' => 'POST']);
    }

    private function requestConDatos(array $datos): Request
    {
        return new Request([], $datos, [], [], [], ['REQUEST_METHOD' => 'POST']);
    }

    private function crearHotel(array $fotos = []): OpcionHotel
    {
        return OpcionHotel::create(['nombre_hotel' => 'Hotel Test Fotos', 'moneda' => 'USD', 'fotos' => $fotos]);
    }

    public function test_agrega_foto_de_fachada_y_la_resuelve_con_tipo_y_url(): void
    {
        $hotel = $this->crearHotel();
        $foto = UploadedFile::fake()->image('foto.jpg', 100, 100);

        $response = $this->controller->agregarFotos($this->requestConFoto($foto, 'fachada'), (string) $hotel->id);
        $data = $response->getData(true);

        $this->assertSame(200, $data['code']);
        $this->assertCount(1, $hotel->fresh()->fotos);
        $this->assertSame('fachada', $data['opcion_hotel']['fotos'][0]['tipo_foto']);
        $this->assertStringContainsString('/tenancy/assets/opciones-hotel/', $data['opcion_hotel']['fotos'][0]['url']);
    }

    public function test_rechaza_segunda_foto_de_fachada(): void
    {
        $hotel = $this->crearHotel([['path' => 'opciones-hotel/a.jpg', 'tipo_foto' => 'fachada']]);
        $nueva = UploadedFile::fake()->image('otra-fachada.jpg', 100, 100);

        $response = $this->controller->agregarFotos($this->requestConFoto($nueva, 'fachada'), (string) $hotel->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('fachada', $response->getData(true)['message']);
        $this->assertCount(1, $hotel->fresh()->fotos);
    }

    public function test_rechaza_tercera_foto_de_habitacion(): void
    {
        $hotel = $this->crearHotel([
            ['path' => 'opciones-hotel/a.jpg', 'tipo_foto' => 'habitacion'],
            ['path' => 'opciones-hotel/b.jpg', 'tipo_foto' => 'habitacion'],
        ]);
        $nueva = UploadedFile::fake()->image('tercera.jpg', 100, 100);

        $response = $this->controller->agregarFotos($this->requestConFoto($nueva, 'habitacion'), (string) $hotel->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('habitacion', $response->getData(true)['message']);
        $this->assertCount(2, $hotel->fresh()->fotos);
    }

    public function test_admite_fachada_mas_dos_habitaciones_sin_rechazar(): void
    {
        $hotel = $this->crearHotel([
            ['path' => 'opciones-hotel/a.jpg', 'tipo_foto' => 'fachada'],
            ['path' => 'opciones-hotel/b.jpg', 'tipo_foto' => 'habitacion'],
        ]);
        $nueva = UploadedFile::fake()->image('segunda-habitacion.jpg', 100, 100);

        $response = $this->controller->agregarFotos($this->requestConFoto($nueva, 'habitacion'), (string) $hotel->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(3, $hotel->fresh()->fotos);
    }

    public function test_eliminar_foto_borra_archivo_fisico_y_lo_saca_del_array(): void
    {
        $archivo = UploadedFile::fake()->image('foto.jpg', 100, 100);
        $hotel = $this->crearHotel();
        $creado = $this->controller->agregarFotos($this->requestConFoto($archivo, 'fachada'), (string) $hotel->id)->getData(true);

        $pathRelativo = $hotel->fresh()->fotos[0]['path'];
        $urlResuelta = $creado['opcion_hotel']['fotos'][0]['url'];
        $this->assertTrue(Storage::disk('public')->exists($pathRelativo));

        $response = $this->controller->eliminarFoto($this->requestConDatos(['path' => $urlResuelta]), (string) $hotel->id);
        $data = $response->getData(true);

        $this->assertSame(200, $data['code']);
        $this->assertFalse(Storage::disk('public')->exists($pathRelativo));
        $this->assertEmpty($hotel->fresh()->fotos);
    }

    public function test_eliminar_foto_rechaza_path_que_no_pertenece_al_hotel(): void
    {
        $hotel = $this->crearHotel([['path' => 'opciones-hotel/real.jpg', 'tipo_foto' => 'fachada']]);

        $response = $this->controller->eliminarFoto($this->requestConDatos(['path' => 'opciones-hotel/otro.jpg']), (string) $hotel->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $hotel->fresh()->fotos);
    }

    // Belt-and-suspenders (Paso 0 del brief de mejora del PDF) — una entrada
    // vieja (string suelto, forma pre-migración) no debe romper el conteo
    // por tipo ni la resolución de URL, aunque en la práctica la migración
    // de datos 2026_09_05_100400 ya debería haber convertido todo lo real.
    public function test_normaliza_entrada_vieja_en_formato_string_suelto(): void
    {
        $hotel = $this->crearHotel(['opciones-hotel/legacy.jpg']);
        $nueva = UploadedFile::fake()->image('fachada.jpg', 100, 100);

        $response = $this->controller->agregarFotos($this->requestConFoto($nueva, 'fachada'), (string) $hotel->id);
        $data = $response->getData(true);

        $this->assertSame(200, $data['code']);
        $this->assertCount(2, $hotel->fresh()->fotos);
        $this->assertSame('habitacion', $data['opcion_hotel']['fotos'][0]['tipo_foto']);
        $this->assertSame('fachada', $data['opcion_hotel']['fotos'][1]['tipo_foto']);
    }
}
