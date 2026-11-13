<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\OpcionHotelController;
use App\Http\Controllers\AgenciaViajes\ProveedorController;
use App\Models\AgenciaViajes\Concerns\FotosConTipo;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\ProveedorTipoConfig;
use App\Models\AgenciaViajes\Servicio;
use App\Services\AgenciaViajes\FotoUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-020 — fotos de hotel con tipo (fachada / habitación / otra) en la galería del proveedor.
// Mismo patrón de infraestructura que OpcionHotelFotosTest: controller invocado
// directamente, Postgres real (sistemafe_test_migrations), transacción por test,
// Storage::fake('public') aísla los archivos.
class ProveedorFotosTipoTest extends TestCase
{
    use ConAccesoATodasLasReservas;

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
        $this->verTodasLasReservas();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function req(array $datos): Request
    {
        return new Request([], $datos, [], [], [], ['REQUEST_METHOD' => 'POST']);
    }

    private function controller(): ProveedorController
    {
        return new ProveedorController(new FotoUploadService());
    }

    private function crearProveedor(array $fotos = []): Proveedor
    {
        // Se guarda por DB para poder sembrar también la forma vieja (string[]).
        $proveedor = Proveedor::create(['razon_social' => 'Hotel Fotos SAC', 'estado' => true]);
        DB::table('proveedores')->where('id', $proveedor->id)->update(['fotos' => json_encode($fotos)]);

        return $proveedor->fresh();
    }

    private function archivo(string $path): string
    {
        Storage::disk('public')->put($path, 'contenido-' . $path);

        return $path;
    }

    // ── CA1 — migración de datos ─────────────────────────────────────────

    private function correrMigracion(): void
    {
        $archivo = glob(database_path('migrations/tenant/verticals/agencia-viajes/2026_11_13_*_migrar_fotos_proveedores_a_tipo_foto.php'))[0] ?? null;
        $this->assertNotNull($archivo, 'Falta la migración de datos de fotos de proveedores.');
        (require $archivo)->up();
    }

    public function test_ca1_migracion_deja_las_fotos_existentes_como_otra_y_es_idempotente(): void
    {
        $viejo = $this->crearProveedor(['proveedores/a.jpg', 'proveedores/b.jpg']);
        $nuevo = $this->crearProveedor([['path' => 'proveedores/c.jpg', 'tipo_foto' => 'fachada']]);
        $sinFotos = $this->crearProveedor([]);

        $this->correrMigracion();

        $this->assertSame(
            [['path' => 'proveedores/a.jpg', 'tipo_foto' => 'otra'], ['path' => 'proveedores/b.jpg', 'tipo_foto' => 'otra']],
            $viejo->fresh()->fotos
        );
        $this->assertSame([['path' => 'proveedores/c.jpg', 'tipo_foto' => 'fachada']], $nuevo->fresh()->fotos);
        $this->assertSame([], $sinFotos->fresh()->fotos);

        $antes = $viejo->fresh()->fotos;
        $this->correrMigracion();
        $this->assertSame($antes, $viejo->fresh()->fotos);
    }

    // ── Helpers compartidos (CA6) ────────────────────────────────────────

    public function test_helpers_compartidos_con_tipo_por_defecto_distinto(): void
    {
        $this->assertContains(FotosConTipo::class, class_uses(OpcionHotel::class));
        $this->assertContains(FotosConTipo::class, class_uses(Proveedor::class));

        $this->assertSame('habitacion', OpcionHotel::normalizarFotos(['x.jpg'])[0]['tipo_foto']);
        $this->assertSame('otra', Proveedor::normalizarFotos(['x.jpg'])[0]['tipo_foto']);
        $this->assertSame('otra', Proveedor::fotosResueltas(['x.jpg'])[0]['tipo_foto']);
        $this->assertStringContainsString('x.jpg', Proveedor::fotosResueltas(['x.jpg'])[0]['url']);
    }

    // ── CA2 / CA3 — cambiar el tipo ──────────────────────────────────────

    public function test_ca2_marcar_fachada_desmarca_la_anterior(): void
    {
        $proveedor = $this->crearProveedor([
            ['path' => 'proveedores/a.jpg', 'tipo_foto' => 'fachada'],
            ['path' => 'proveedores/b.jpg', 'tipo_foto' => 'otra'],
        ]);

        $respuesta = $this->controller()->actualizarTipoFoto(
            $this->req(['path' => 'proveedores/b.jpg', 'tipo_foto' => 'fachada']),
            (string) $proveedor->id
        );

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $this->assertSame(
            [['path' => 'proveedores/a.jpg', 'tipo_foto' => 'otra'], ['path' => 'proveedores/b.jpg', 'tipo_foto' => 'fachada']],
            $proveedor->fresh()->fotos
        );
        $devueltas = $respuesta->getData(true)['proveedor']['fotos'];
        $this->assertSame('fachada', $devueltas[1]['tipo_foto']);
        $this->assertArrayHasKey('url', $devueltas[1]);
    }

    public function test_ca2_habitacion_no_desmarca_la_fachada(): void
    {
        $proveedor = $this->crearProveedor([
            ['path' => 'proveedores/a.jpg', 'tipo_foto' => 'fachada'],
            ['path' => 'proveedores/b.jpg', 'tipo_foto' => 'otra'],
        ]);

        $this->controller()->actualizarTipoFoto(
            $this->req(['path' => 'proveedores/b.jpg', 'tipo_foto' => 'habitacion']),
            (string) $proveedor->id
        );

        $this->assertSame(['fachada', 'habitacion'], array_column($proveedor->fresh()->fotos, 'tipo_foto'));
    }

    public function test_ca3_path_ajeno_o_tipo_invalido_dan_422(): void
    {
        $proveedor = $this->crearProveedor([['path' => 'proveedores/a.jpg', 'tipo_foto' => 'otra']]);

        $ajeno = $this->controller()->actualizarTipoFoto(
            $this->req(['path' => 'proveedores/zzz.jpg', 'tipo_foto' => 'fachada']),
            (string) $proveedor->id
        );
        $invalido = $this->controller()->actualizarTipoFoto(
            $this->req(['path' => 'proveedores/a.jpg', 'tipo_foto' => 'portada']),
            (string) $proveedor->id
        );

        $this->assertSame(422, $ajeno->getStatusCode());
        $this->assertSame(422, $invalido->getStatusCode());
        $this->assertSame([['path' => 'proveedores/a.jpg', 'tipo_foto' => 'otra']], $proveedor->fresh()->fotos);
    }

    public function test_ca3_acepta_la_url_resuelta_como_path(): void
    {
        $proveedor = $this->crearProveedor([['path' => 'proveedores/a.jpg', 'tipo_foto' => 'otra']]);
        $url = Proveedor::fotosResueltas($proveedor->fotos)[0]['url'];

        $respuesta = $this->controller()->actualizarTipoFoto(
            $this->req(['path' => $url, 'tipo_foto' => 'fachada']),
            (string) $proveedor->id
        );

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $this->assertSame('fachada', $proveedor->fresh()->fotos[0]['tipo_foto']);
    }

    // ── CA4 — subir y borrar ─────────────────────────────────────────────

    public function test_ca4_subir_fotos_las_deja_como_otra_y_conserva_las_anteriores(): void
    {
        $tipo = ProveedorTipo::where('slug', ProveedorTipo::SLUG_ALOJAMIENTO)->first();
        if (! $tipo) {
            $this->markTestSkipped('Catálogo central proveedor_tipos sin el slug alojamiento-hoteles en este entorno.');
        }
        ProveedorTipoConfig::firstOrCreate(['proveedor_tipo_id' => $tipo->id], ['habilitado' => true]);
        $proveedor = $this->crearProveedor([['path' => 'proveedores/a.jpg', 'tipo_foto' => 'fachada']]);

        $respuesta = $this->controller()->update(
            new Request([], ['razon_social' => 'Hotel Fotos SAC', 'tipo_id' => $tipo->id], [], [], [
                'fotos' => [UploadedFile::fake()->image('n1.jpg', 100, 100), UploadedFile::fake()->image('n2.jpg', 100, 100)],
            ], ['REQUEST_METHOD' => 'POST']),
            (string) $proveedor->id
        );

        $this->assertSame(200, $respuesta->getData(true)['code'], (string) ($respuesta->getData(true)['message'] ?? ''));
        $fotos = $proveedor->fresh()->fotos;
        $this->assertCount(3, $fotos);
        $this->assertSame(['fachada', 'otra', 'otra'], array_column($fotos, 'tipo_foto'));
        $resp = $respuesta->getData(true)['proveedor']['fotos'];
        $this->assertSame($fotos[1]['path'], $resp[1]['path']);
        $this->assertStringContainsString($fotos[1]['path'], $resp[1]['url']);
    }

    public function test_ca4_show_devuelve_path_tipo_y_url(): void
    {
        $proveedor = $this->crearProveedor([['path' => 'proveedores/a.jpg', 'tipo_foto' => 'fachada']]);

        $foto = $this->controller()->show((string) $proveedor->id)->getData(true)['proveedor']['fotos'][0];

        $this->assertSame('proveedores/a.jpg', $foto['path']);
        $this->assertSame('fachada', $foto['tipo_foto']);
        $this->assertStringContainsString('proveedores/a.jpg', $foto['url']);
    }

    public function test_ca4_borrar_foto_la_saca_del_array_y_borra_el_archivo(): void
    {
        $a = $this->archivo('proveedores/a.jpg');
        $b = $this->archivo('proveedores/b.jpg');
        $proveedor = $this->crearProveedor([['path' => $a, 'tipo_foto' => 'fachada'], ['path' => $b, 'tipo_foto' => 'otra']]);

        $respuesta = $this->controller()->eliminarFoto($this->req(['path' => $a]), (string) $proveedor->id);

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        Storage::disk('public')->assertMissing($a);
        Storage::disk('public')->assertExists($b);
        // Borrar la fachada deja al proveedor sin fachada: nadie la hereda.
        $this->assertSame([['path' => $b, 'tipo_foto' => 'otra']], $proveedor->fresh()->fotos);
    }

    public function test_ca4_borrar_path_ajeno_da_422_y_no_borra_nada(): void
    {
        $a = $this->archivo('proveedores/a.jpg');
        $ajeno = $this->archivo('proveedores/de-otro.jpg');
        $proveedor = $this->crearProveedor([['path' => $a, 'tipo_foto' => 'otra']]);

        $respuesta = $this->controller()->eliminarFoto($this->req(['path' => $ajeno]), (string) $proveedor->id);

        $this->assertSame(422, $respuesta->getStatusCode());
        Storage::disk('public')->assertExists($ajeno);
        Storage::disk('public')->assertExists($a);
        $this->assertCount(1, $proveedor->fresh()->fotos);
    }

    // ── CA5 — promover copia los archivos con su tipo ────────────────────

    public function test_ca5_promover_copia_fotos_con_tipo_a_archivos_propios(): void
    {
        $tipo = ProveedorTipo::where('slug', ProveedorTipo::SLUG_ALOJAMIENTO)->first();
        if (! $tipo) {
            $this->markTestSkipped('Catálogo central proveedor_tipos sin el slug alojamiento-hoteles en este entorno.');
        }
        ProveedorTipoConfig::firstOrCreate(['proveedor_tipo_id' => $tipo->id], ['habilitado' => true]);
        $servicio = Servicio::create(['nombre' => 'Hospedaje T020 ' . random_int(1000, 9999), 'tipo_proveedor_id' => $tipo->id]);
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Tarapoto T020', 'tipo' => 'lugar']);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);

        $fachada = $this->archivo('opciones-hotel/f.jpg');
        $h1 = $this->archivo('opciones-hotel/h1.jpg');
        $h2 = $this->archivo('opciones-hotel/h2.jpg');
        $hotel = OpcionHotel::create([
            'nombre_hotel' => 'Hotel Promover Fotos', 'moneda' => 'PEN',
            'fotos' => [
                ['path' => $fachada, 'tipo_foto' => 'fachada'],
                ['path' => $h1, 'tipo_foto' => 'habitacion'],
                ['path' => $h2, 'tipo_foto' => 'habitacion'],
            ],
        ]);
        OpcionHotelTarifa::create(['opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 80, 'precio_venta' => 120, 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional']);

        $controller = new OpcionHotelController(new FotoUploadService());
        $respuesta = $controller->promover(
            new Request(['destino_servicio_id' => $destinoServicio->id, 'razon_social' => 'Hotel Promover Fotos SAC']),
            (string) $hotel->id
        );
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        $proveedor = Proveedor::findOrFail($respuesta->getData(true)['proveedor']['id']);
        $fotos = $proveedor->fotos;
        $this->assertSame(['fachada', 'habitacion', 'habitacion'], array_column($fotos, 'tipo_foto'));
        foreach ($fotos as $foto) {
            $this->assertStringStartsWith('proveedores/', $foto['path']);
            Storage::disk('public')->assertExists($foto['path']);
        }
        // Archivos propios: ningún path compartido con el hotel.
        $this->assertSame([], array_values(array_intersect(array_column($fotos, 'path'), [$fachada, $h1, $h2])));

        // Borrar una foto del hotel después no rompe la del proveedor.
        $controller->eliminarFoto($this->req(['path' => $fachada]), (string) $hotel->id);
        Storage::disk('public')->assertMissing($fachada);
        Storage::disk('public')->assertExists($fotos[0]['path']);
        $this->assertCount(3, $proveedor->fresh()->fotos);
    }
}
