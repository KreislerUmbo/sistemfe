<?php

namespace Tests\Feature;

use App\Http\Controllers\Sale\ArchivoSunatController;
use App\Models\Sale\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-069: el XML y el CDR se descargaban con la dirección guardada
// ("/storage/cdrs/x.zip"), que nginx sirve desde la carpeta central: 404 en
// producción (el archivo vive en la del negocio). Ahora, como el PDF, por una
// dirección firmada que lee el disco del negocio.
class ArchivoSunatTest extends TestCase
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

        DB::table('roles')->insertOrIgnore(['id' => 1, 'name' => 'rol-archivo-sunat', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function ventaAceptada(): Sale
    {
        Storage::disk('public')->put('xml/R-20123456789-1-F001-1.xml', '<Invoice/>');
        Storage::disk('public')->put('cdrs/abc123.zip', 'contenido-cdr');

        return Sale::factory()->create([
            'xml' => '/storage/xml/R-20123456789-1-F001-1.xml',
            'cdr' => '/storage/cdrs/abc123.zip',
        ]);
    }

    public function test_la_ruta_guardada_se_lee_del_disco_del_negocio(): void
    {
        $this->assertSame('cdrs/abc.zip', ArchivoSunatController::rutaEnDisco('/storage/cdrs/abc.zip'));
        $this->assertSame('cdrs/abc.zip', ArchivoSunatController::rutaEnDisco('https://market.umbosystem.com/storage/cdrs/abc.zip'));
        $this->assertNull(ArchivoSunatController::rutaEnDisco('/storage/../.env'), 'no sale de la carpeta');
        $this->assertNull(ArchivoSunatController::rutaEnDisco(null));
    }

    public function test_la_url_firmada_apunta_a_la_ruta_de_descarga(): void
    {
        $venta = $this->ventaAceptada();

        $respuesta = app(ArchivoSunatController::class)->urlFirmada(new Request(['archivo' => 'cdr']), 'venta', (string) $venta->id);

        $url = $respuesta->getData(true)['url'];
        $this->assertStringContainsString("/api/sunat-archivo/venta/{$venta->id}/cdr", $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    public function test_descarga_el_xml_y_el_cdr(): void
    {
        $venta = $this->ventaAceptada();
        $controlador = app(ArchivoSunatController::class);

        $cdr = $controlador->descargar('venta', (string) $venta->id, 'cdr');
        $this->assertSame(200, $cdr->getStatusCode());
        $this->assertStringContainsString('abc123.zip', $cdr->headers->get('content-disposition'));

        $xml = $controlador->descargar('venta', (string) $venta->id, 'xml');
        $this->assertStringContainsString('R-20123456789-1-F001-1.xml', $xml->headers->get('content-disposition'));
    }

    public function test_archivo_que_ya_no_esta_da_404(): void
    {
        $venta = Sale::factory()->create(['xml' => '/storage/xml/no-existe.xml', 'cdr' => null]);

        try {
            app(ArchivoSunatController::class)->descargar('venta', (string) $venta->id, 'xml');
            $this->fail('Se esperaba 404.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $sinCdr = app(ArchivoSunatController::class)->urlFirmada(new Request(['archivo' => 'cdr']), 'venta', (string) $venta->id);
        $this->assertSame(404, $sinCdr->getStatusCode());
    }

    public function test_archivo_invalido_422(): void
    {
        $venta = $this->ventaAceptada();

        $respuesta = app(ArchivoSunatController::class)->urlFirmada(new Request(['archivo' => 'pdf']), 'venta', (string) $venta->id);

        $this->assertSame(422, $respuesta->getStatusCode());
    }

    public function test_la_descarga_exige_firma_y_el_negocio(): void
    {
        $ruta = Route::getRoutes()->getByName('sunat.archivo');

        $this->assertNotNull($ruta);
        foreach (['tenant', 'tenant.active', 'tenant.token', 'signed'] as $middleware) {
            $this->assertContains($middleware, $ruta->gatherMiddleware());
        }
    }
}
