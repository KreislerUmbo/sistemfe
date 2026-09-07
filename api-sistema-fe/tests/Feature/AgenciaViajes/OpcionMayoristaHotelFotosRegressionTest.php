<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\OpcionMayoristaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTipo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Bug real reportado por el usuario (05-sep-2026), probando en vivo el
// comparador de mayoristas: "Internacional > Elegir mayorista > Agregar
// hoteles > Agregar imágenes" tiraba
// "App\Services\StorageUrl::resolve(): Argument #1 ($path) must be of
// type ?string, array given". Causa real: la mejora del PDF de
// cotización (plan-mejora-pdf-cotizacion-cliente.md §4.5) cambió
// `opciones_hotel.fotos` de string[] a {path, tipo_foto}[] y actualizó
// OpcionHotelController (flujo Local ad-hoc), pero
// OpcionMayoristaController (flujo Internacional/mayorista — un
// controller DISTINTO que también resuelve fotos de OpcionHotel, ver
// docblock de OpcionHotelController) seguía llamando
// StorageUrl::resolveMuchas() directo, que espera string[]. Fix: la
// normalización se extrajo a OpcionHotel::fotosResueltas() (modelo, no
// controller) para que no se pueda repetir en un tercer lugar.
class OpcionMayoristaHotelFotosRegressionTest extends TestCase
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

    private function crearAlternativaConOpcionYHotelConFotos(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '99887766', 'full_name' => 'Cliente Test Fotos Mayorista',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-FOTOSM-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Cusco', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa Fotos Mayorista', 'estado' => 'borrador',
            'moneda_cotizacion' => 'USD', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        $tipoMayorista = ProveedorTipo::where('slug', ProveedorTipo::SLUG_MAYORISTA)->first();
        if (! $tipoMayorista) {
            $this->markTestSkipped('Catálogo central proveedor_tipos sin el slug agencia-mayorista en este entorno.');
        }
        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Test Fotos SAC', 'estado' => true, 'tipo_id' => $tipoMayorista->id]);

        $opcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id,
            'moneda' => 'USD', 'estado' => 'elegida',
        ]);

        // Forma real post-mejora del PDF ({path, tipo_foto}), la que
        // rompía antes del fix.
        $hotel = OpcionHotel::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre_hotel' => 'Hotel Test Fotos Mayorista', 'moneda' => 'USD',
            'fotos' => [
                ['path' => 'opciones-hotel/fachada.jpg', 'tipo_foto' => 'fachada'],
                ['path' => 'opciones-hotel/habitacion.jpg', 'tipo_foto' => 'habitacion'],
            ],
        ]);

        return [$alternativa, $opcion, $hotel];
    }

    public function test_index_no_explota_con_hotel_que_tiene_fotos(): void
    {
        [$alternativa] = $this->crearAlternativaConOpcionYHotelConFotos();

        $response = app(OpcionMayoristaController::class)->index((string) $alternativa->id);
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $fotos = $data['opciones_mayorista'][0]['opciones_hotel'][0]['fotos'];
        $this->assertCount(2, $fotos);
        $this->assertSame('fachada', $fotos[0]['tipo_foto']);
        $this->assertStringContainsString('/tenancy/assets/opciones-hotel/fachada.jpg', $fotos[0]['url']);
    }

    public function test_hoteles_get_no_explota_con_hotel_que_tiene_fotos(): void
    {
        [, $opcion] = $this->crearAlternativaConOpcionYHotelConFotos();

        $response = app(OpcionMayoristaController::class)->hoteles(
            new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']),
            (string) $opcion->id
        );
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $data['opciones_hotel'][0]['fotos']);
    }

    public function test_hoteles_post_no_explota_al_crear_hotel_y_resolver_fotos_vacias(): void
    {
        [, $opcion] = $this->crearAlternativaConOpcionYHotelConFotos();

        $response = app(OpcionMayoristaController::class)->hoteles(
            new Request([], ['nombre_hotel' => 'Hotel Nuevo Sin Fotos'], [], [], [], ['REQUEST_METHOD' => 'POST']),
            (string) $opcion->id
        );
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $data['opcion_hotel']['fotos']);
    }
}
