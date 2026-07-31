<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\DestinoAtractivoController;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Services\AgenciaViajes\FotoUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Sesión "destinos-atractivos-form" — límites de peso/cantidad, redimensionado
// automático y corrección de orientación EXIF al subir fotos, más los
// endpoints nuevos de eliminar-una-foto y reordenar/portada. Mismo patrón de
// test que PaqueteComboTest (controller invocado directamente, sin capa
// HTTP/auth/tenancy — corre contra sistemafe_test_migrations real, transacción
// por test revertida en tearDown()). Storage::fake('public') aísla los
// archivos de prueba del disco real.
class DestinoAtractivoFotosTest extends TestCase
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

        Storage::fake('public');

        $this->controller = new DestinoAtractivoController(new FotoUploadService());
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    // Construye un Request con archivos bajo 'fotos' (uno o varios), mismo
    // formato que llegaría de un <input type="file" multiple name="fotos[]">.
    // REQUEST_METHOD=POST explícito: Request::getInputSource() lee de
    // $this->query (no $this->request) cuando el método es GET/HEAD — sin
    // esto, $request->all()/->get() no encuentran los datos del segundo
    // argumento del constructor.
    private function requestConFotos(array $datos, array $fotos): Request
    {
        return new Request([], $datos, [], [], ['fotos' => $fotos], ['REQUEST_METHOD' => 'POST']);
    }

    private function requestConDatos(array $datos): Request
    {
        return new Request([], $datos, [], [], [], ['REQUEST_METHOD' => 'POST']);
    }

    // JPEG real (vía GD) con un segmento EXIF APP1 inyectado a mano —
    // UploadedFile::fake()->image() no soporta EXIF personalizado, así que se
    // arma el binario mínimo necesario para que exif_read_data() (usado por
    // Intervention/Image internamente) lea Orientation=$orientacion.
    private function crearJpegConOrientacion(int $width, int $height, int $orientacion): string
    {
        $imagen = imagecreatetruecolor($width, $height);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 200, 50, 50));
        ob_start();
        imagejpeg($imagen, null, 90);
        $jpegData = ob_get_clean();

        $tiffHeader = 'II'.pack('v', 42).pack('V', 8);
        $ifdCount = pack('v', 1);
        $entry = pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientacion).pack('v', 0);
        $nextIfdOffset = pack('V', 0);

        $exifPayload = "Exif\0\0".$tiffHeader.$ifdCount.$entry.$nextIfdOffset;
        $app1 = "\xFF\xE1".pack('n', strlen($exifPayload) + 2).$exifPayload;

        // Insertar el APP1 justo después del SOI (0xFFD8).
        return substr($jpegData, 0, 2).$app1.substr($jpegData, 2);
    }

    private function uploadedFileDesdeBinario(string $contenido, string $nombre = 'foto.jpg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'exiftest_').'.jpg';
        file_put_contents($tmp, $contenido);

        return new UploadedFile($tmp, $nombre, 'image/jpeg', null, true);
    }

    public function test_rechaza_foto_que_supera_5mb_sin_bloquear_las_demas(): void
    {
        $pesada = UploadedFile::fake()->image('pesada.jpg', 100, 100)->size(6000); // 6MB
        $liviana = UploadedFile::fake()->image('liviana.jpg', 100, 100)->size(100); // 100KB

        $request = $this->requestConFotos(['nombre' => 'Zona Test', 'tipo' => 'zona'], [$pesada, $liviana]);
        $response = $this->controller->store($request);
        $data = $response->getData(true);

        $this->assertSame(200, $data['code']);
        $this->assertCount(1, $data['fotos_rechazadas']);
        $this->assertSame('pesada.jpg', $data['fotos_rechazadas'][0]['nombre']);

        $destino = DestinoAtractivo::find($data['destino_atractivo']['id']);
        $this->assertCount(1, $destino->fotos);
    }

    public function test_redimensiona_foto_grande_a_maximo_1920px_lado_mayor(): void
    {
        $grande = UploadedFile::fake()->image('grande.jpg', 4000, 3000);

        $request = $this->requestConFotos(['nombre' => 'Zona Grande', 'tipo' => 'zona'], [$grande]);
        $response = $this->controller->store($request);
        $data = $response->getData(true);

        $this->assertSame(200, $data['code']);
        $destino = DestinoAtractivo::find($data['destino_atractivo']['id']);
        $path = $destino->fotos[0];

        [$anchoFinal, $altoFinal] = getimagesize(Storage::disk('public')->path($path));
        $this->assertSame(1920, $anchoFinal);
        $this->assertSame(1440, $altoFinal);
    }

    public function test_corrige_orientacion_exif_de_foto_vertical(): void
    {
        // Buffer físico apaisado (150x100) con EXIF Orientation=6 ("rotar 90°
        // para verse bien") — simula una foto de celular tomada en vertical.
        $binario = $this->crearJpegConOrientacion(150, 100, 6);
        $archivo = $this->uploadedFileDesdeBinario($binario);

        $request = $this->requestConFotos(['nombre' => 'Zona Vertical', 'tipo' => 'zona'], [$archivo]);
        $response = $this->controller->store($request);
        $data = $response->getData(true);

        $this->assertSame(200, $data['code']);
        $destino = DestinoAtractivo::find($data['destino_atractivo']['id']);
        $path = $destino->fotos[0];

        [$anchoFinal, $altoFinal] = getimagesize(Storage::disk('public')->path($path));
        // Tras corregir la orientación, ancho/alto quedan invertidos respecto
        // al buffer físico original (150x100 → 100x150).
        $this->assertSame(100, $anchoFinal);
        $this->assertSame(150, $altoFinal);
    }

    public function test_rechaza_undecima_foto_cuando_ya_hay_diez(): void
    {
        $diezFotos = array_map(fn ($i) => "destinos-atractivos/existente-{$i}.jpg", range(1, 10));
        $destino = DestinoAtractivo::create(['nombre' => 'Zona Llena', 'tipo' => 'zona', 'fotos' => $diezFotos]);

        $nueva = UploadedFile::fake()->image('once.jpg', 100, 100);
        $request = $this->requestConFotos(['nombre' => 'Zona Llena', 'tipo' => 'zona'], [$nueva]);
        $response = $this->controller->update($request, (string) $destino->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('10', $response->getData(true)['message']);

        $destino->refresh();
        $this->assertCount(10, $destino->fotos);
    }

    public function test_eliminar_foto_borra_archivo_fisico_y_lo_saca_del_array(): void
    {
        $archivo = UploadedFile::fake()->image('foto.jpg', 200, 200);
        $request = $this->requestConFotos(['nombre' => 'Zona Elim', 'tipo' => 'zona'], [$archivo]);
        $creado = $this->controller->store($request)->getData(true);

        $destinoId = $creado['destino_atractivo']['id'];
        $path = $creado['destino_atractivo']['fotos'][0];

        $this->assertTrue(Storage::disk('public')->exists($path));

        $deleteRequest = $this->requestConDatos(['path' => $path]);
        $response = $this->controller->eliminarFoto($deleteRequest, (string) $destinoId);
        $data = $response->getData(true);

        $this->assertSame(200, $data['code']);
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertEmpty(DestinoAtractivo::find($destinoId)->fotos);
    }

    public function test_cambiar_portada_persiste_nuevo_orden(): void
    {
        $fotos = ['destinos-atractivos/a.jpg', 'destinos-atractivos/b.jpg', 'destinos-atractivos/c.jpg'];
        $destino = DestinoAtractivo::create(['nombre' => 'Zona Orden', 'tipo' => 'zona', 'fotos' => $fotos]);

        $nuevoOrden = ['destinos-atractivos/c.jpg', 'destinos-atractivos/a.jpg', 'destinos-atractivos/b.jpg'];
        $request = $this->requestConDatos(['fotos' => $nuevoOrden]);
        $response = $this->controller->ordenarFotos($request, (string) $destino->id);
        $data = $response->getData(true);

        $this->assertSame(200, $data['code']);
        $this->assertSame($nuevoOrden, $destino->fresh()->fotos);
    }
}
