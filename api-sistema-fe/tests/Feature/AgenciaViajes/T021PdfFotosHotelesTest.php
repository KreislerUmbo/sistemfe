<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorAlojamientoDetalle;
use App\Services\AgenciaViajes\AlternativaPdfService;
use App\Services\AgenciaViajes\ImagenRecorteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

// T-021 — sección "Fotos referenciales de los hoteles" del PDF de alternativa: un bloque por
// hotel de la alternativa (de una comparación o suelto), sin repetir. hotelesParaPdf() es
// privado: se invoca por reflexión (mismo patrón que SesionM5OpcionesHotelesPdfTest).
class T021PdfFotosHotelesTest extends TestCase
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
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function hoteles(Alternativa $alternativa): array
    {
        $method = new \ReflectionMethod(AlternativaPdfService::class, 'hotelesParaPdf');
        $method->setAccessible(true);

        return $method->invoke(app(AlternativaPdfService::class), $alternativa->fresh([
            'items.proveedorTarifa.proveedorServicio',
            'items.opcionHotelTarifa.opcionHotel',
        ]));
    }

    private function crearAlternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667799', 'full_name' => 'Cliente Test T021',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T021-'.random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa T021', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    // Foto real en el disco fake (el recorte la lee con GD). Devuelve el path.
    private function foto(string $nombre): string
    {
        $img = imagecreatetruecolor(800, 600);
        ob_start();
        imagejpeg($img);
        Storage::disk('public')->put("hoteles/{$nombre}.jpg", ob_get_clean());

        return "hoteles/{$nombre}.jpg";
    }

    // Hotel registrado (Proveedor) con $tipos = ['fachada', 'habitacion', ...] → una foto por tipo.
    // Devuelve el proveedor_servicio_id (de donde cuelgan las tarifas).
    private function crearProveedorHotel(string $nombre, array $tipos, ?string $checkin = null): int
    {
        $fotos = [];
        foreach ($tipos as $i => $tipo) {
            $fotos[] = ['path' => $this->foto(Str::slug($nombre)."-{$i}"), 'tipo_foto' => $tipo];
        }

        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Zona T021 '.random_int(1000, 9999), 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId(['nombre' => 'Hospedaje T021', 'created_at' => now(), 'updated_at' => now()]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedor = Proveedor::create(['razon_social' => $nombre, 'estado' => true, 'fotos' => $fotos]);
        if ($checkin) {
            ProveedorAlojamientoDetalle::create(['proveedor_id' => $proveedor->id, 'hora_checkin' => $checkin, 'hora_checkout' => '11:00:00']);
        }

        return DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicioId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function itemProveedor(Alternativa $alternativa, int $proveedorServicioId, ?string $grupo = null, string $tipoHabitacion = 'doble'): AlternativaItem
    {
        $tarifaId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId, 'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => 70, 'margen_tipo' => 'fijo', 'margen_valor' => 30, 'precio_venta_adulto' => 100,
            'tipo_habitacion' => $tipoHabitacion, 'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifaId,
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => $grupo !== null && $tipoHabitacion === 'doble',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 70, 'precio_venta_snapshot' => 100, 'precio_convertido' => 100,
        ]);
    }

    // Hotel de mayorista (OpcionHotel con sus fotos {path, tipo_foto}).
    private function itemMayorista(Alternativa $alternativa, string $nombre, array $tipos, ?string $grupo = null): AlternativaItem
    {
        $fotos = [];
        foreach ($tipos as $i => $tipo) {
            $fotos[] = ['path' => $this->foto(Str::slug($nombre)."-m{$i}"), 'tipo_foto' => $tipo];
        }
        $opcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id,
            'proveedor_id' => Proveedor::create(['razon_social' => 'Mayorista T021 '.random_int(1000, 9999), 'estado' => true])->id,
            'moneda' => 'PEN', 'estado' => 'elegida',
        ]);
        $hotel = OpcionHotel::create(['opcion_mayorista_id' => $opcion->id, 'nombre_hotel' => $nombre, 'moneda' => 'PEN', 'fotos' => $fotos]);
        $tarifa = OpcionHotelTarifa::create(['opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 70, 'precio_venta' => 100]);

        return AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'mayorista',
            'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id,
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => $grupo !== null,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 2, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 70, 'precio_venta_snapshot' => 100, 'precio_convertido' => 100,
        ]);
    }

    // CA1
    public function test_hotel_proveedor_suelto_sale_con_fachada_primero_dos_habitaciones_y_checkin(): void
    {
        $alternativa = $this->crearAlternativa();
        // Sobran fotos (3 habitación + 1 otra): solo entran fachada + 2 habitación.
        $ps = $this->crearProveedorHotel('Hotel Suelto', ['habitacion', 'otra', 'habitacion', 'fachada', 'habitacion'], '14:00:00');
        $this->itemProveedor($alternativa, $ps);

        $lista = $this->hoteles($alternativa);

        $this->assertCount(1, $lista);
        $this->assertSame('Hotel Suelto', $lista[0]['nombre']);
        $this->assertCount(3, $lista[0]['fotos']);
        $this->assertSame('14:00:00', $lista[0]['check_in']);
        $this->assertSame('11:00:00', $lista[0]['check_out']);

        // La fachada va primera: es la foto del path "…-3" (la 4.ª cargada).
        $fachada = app(ImagenRecorteService::class)->recortar4x3ParaPdf('hoteles/hotel-suelto-3.jpg');
        $this->assertSame($fachada, $lista[0]['fotos'][0]);
    }

    // CA2
    public function test_comparacion_de_dos_hoteles_proveedor_da_dos_bloques(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->itemProveedor($alternativa, $this->crearProveedorHotel('Hotel A', ['fachada']), $grupo);
        $this->itemProveedor($alternativa, $this->crearProveedorHotel('Hotel B', ['fachada']), $grupo);

        $this->assertSame(['Hotel A', 'Hotel B'], array_column($this->hoteles($alternativa), 'nombre'));
    }

    // CA3
    public function test_hotel_con_solo_dos_fotos_otra_sale_con_esas_dos(): void
    {
        $alternativa = $this->crearAlternativa();
        $this->itemProveedor($alternativa, $this->crearProveedorHotel('Hotel Otras', ['otra', 'otra']));

        $lista = $this->hoteles($alternativa);

        $this->assertCount(1, $lista);
        $this->assertCount(2, $lista[0]['fotos']);
    }

    public function test_las_otra_completan_hasta_tres_tras_fachada_y_habitacion(): void
    {
        $alternativa = $this->crearAlternativa();
        $this->itemProveedor($alternativa, $this->crearProveedorHotel('Hotel Mixto', ['fachada', 'habitacion', 'otra', 'otra']));

        $this->assertCount(3, $this->hoteles($alternativa)[0]['fotos']);
    }

    // CA4
    public function test_mismo_hotel_en_dos_filas_sale_un_solo_bloque(): void
    {
        $alternativa = $this->crearAlternativa();
        $ps = $this->crearProveedorHotel('Hotel Repetido', ['fachada']);
        $this->itemProveedor($alternativa, $ps, null, 'doble');
        $this->itemProveedor($alternativa, $ps, null, 'matrimonial');

        $this->assertCount(1, $this->hoteles($alternativa));
    }

    // CA5
    public function test_hotel_de_mayorista_con_fotos_sale_como_antes(): void
    {
        $alternativa = $this->crearAlternativa();
        $this->itemMayorista($alternativa, 'Hotel Mayorista', ['fachada', 'habitacion', 'habitacion'], (string) Str::uuid());

        $lista = $this->hoteles($alternativa);

        $this->assertCount(1, $lista);
        $this->assertSame('Hotel Mayorista', $lista[0]['nombre']);
        $this->assertCount(3, $lista[0]['fotos']);
    }

    public function test_grupo_resuelto_tambien_muestra_los_hoteles_no_elegidos(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->itemProveedor($alternativa, $this->crearProveedorHotel('Elegido', ['fachada']), $grupo, 'doble');
        // tipo distinto a 'doble' → opcion_elegida=false (ver itemProveedor).
        $this->itemProveedor($alternativa, $this->crearProveedorHotel('No Elegido', ['fachada']), $grupo, 'triple');

        $this->assertSame(['Elegido', 'No Elegido'], array_column($this->hoteles($alternativa), 'nombre'));
    }

    public function test_hotel_sin_fotos_no_imprime_bloque(): void
    {
        $alternativa = $this->crearAlternativa();
        $this->itemProveedor($alternativa, $this->crearProveedorHotel('Sin Fotos', []));
        $this->itemProveedor($alternativa, $this->crearProveedorHotel('Con Fotos', ['fachada']));

        $this->assertSame(['Con Fotos'], array_column($this->hoteles($alternativa), 'nombre'));
    }

    // CA6 (a nivel de HTML real de la plantilla)
    private function htmlDelPdf(Alternativa $alternativa): string
    {
        $datos = null;
        View::composer('pdf.agencia-viajes.alternativa', function ($view) use (&$datos) {
            $datos ??= $view->getData();
        });

        app(AlternativaPdfService::class)->contenido((string) $alternativa->id);

        return view('pdf.agencia-viajes.alternativa', $datos)->render();
    }

    public function test_html_sin_ningun_hotel_con_fotos_no_trae_la_seccion(): void
    {
        $alternativa = $this->crearAlternativa();
        $this->itemProveedor($alternativa, $this->crearProveedorHotel('Sin Fotos', []));

        $this->assertStringNotContainsString('>Fotos referenciales de los hoteles</div>', $this->htmlDelPdf($alternativa));
    }

    public function test_html_trae_una_sola_seccion_con_el_nombre_escapado_de_cada_hotel(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->itemProveedor($alternativa, $this->crearProveedorHotel('Hotel <b>Uno</b>', ['fachada']), $grupo);
        $this->itemProveedor($alternativa, $this->crearProveedorHotel('Hotel Dos', ['fachada']), $grupo);

        $html = $this->htmlDelPdf($alternativa);

        $this->assertSame(1, substr_count($html, '>Fotos referenciales de los hoteles</div>'));
        $this->assertSame(2, substr_count($html, 'class="hotel-fotos-nombre"'));
        $this->assertStringContainsString('Hotel &lt;b&gt;Uno&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<div class="hotel-fotos-nombre">Hotel <b>', $html);
    }
}
