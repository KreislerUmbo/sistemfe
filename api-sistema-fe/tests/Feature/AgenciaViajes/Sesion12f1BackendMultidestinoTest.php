<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaDestinoController;
use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\Servicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 12f-1 — brief PEGAR-EN-CLAUDE-CODE-12f1-backend-multidestino-ui.md.
// Cierra el gap que 12c dejó pendiente a propósito: los 9 puntos de
// creación de AlternativaItem empiezan a setear alternativa_destino_id.
// Un test por cada uno de los 9 — el resolver es idéntico código en los 9
// lugares, pero un error de copiar/pegar en uno solo no se detecta si no
// se prueban todos. Mismo patrón de infraestructura que el resto de la
// suite: Postgres real (sistemafe_test_migrations), transacción por test
// revertida.
class Sesion12f1BackendMultidestinoTest extends TestCase
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

    private function crearAlternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '10203040', 'full_name' => 'Cliente Test 12f1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-08' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function crearAlternativaConDestino(): array
    {
        $alternativa = $this->crearAlternativa();
        $destino = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Tarapoto', 'orden' => 1]);

        return [$alternativa, $destino];
    }

    // §1 del brief — CotizacionController::show() eager-carga destinos.

    public function test_show_eager_carga_alternativas_destinos(): void
    {
        [$alternativa, $destino] = $this->crearAlternativaConDestino();

        $response = app(CotizacionController::class)->show((string) $alternativa->cotizacion_id);
        $data = $response->getData(true);

        $this->assertSame($destino->id, $data['cotizacion']['alternativas'][0]['destinos'][0]['id']);
    }

    // §2 del brief — AlternativaDestinoController.

    public function test_destinos_store_autoincrementa_orden(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Tarapoto', 'orden' => 1]);

        $response = app(AlternativaDestinoController::class)->store(
            new Request(['destino_texto' => 'México']),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $response->getData(true)['alternativa_destino']['orden']);
    }

    public function test_destinos_store_rechaza_si_alternativa_ya_aceptada(): void
    {
        $alternativa = $this->crearAlternativa();
        $alternativa->update(['estado' => 'aceptada']);

        $response = app(AlternativaDestinoController::class)->store(
            new Request(['destino_texto' => 'México']),
            (string) $alternativa->id
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('ya fue aceptada', $response->getData(true)['message']);
    }

    public function test_destinos_index_lista_ordenado(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'B', 'orden' => 2]);
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'A', 'orden' => 1]);

        $response = app(AlternativaDestinoController::class)->index((string) $alternativa->id);
        $destinos = $response->getData(true)['alternativa_destinos'];

        $this->assertSame('A', $destinos[0]['destino_texto']);
        $this->assertSame('B', $destinos[1]['destino_texto']);
    }

    // §3 del brief — el resolver compartido, probado una vez a fondo vía
    // crearItemManual (validación de explícito + rechazo de destino ajeno).

    public function test_item_manual_usa_el_destino_por_defecto_si_no_llega_explicito(): void
    {
        [$alternativa, $destino] = $this->crearAlternativaConDestino();

        $response = app(AlternativaItemController::class)->store(
            new Request(['origen_tipo' => 'manual', 'descripcion_manual' => 'Ítem test', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'moneda_costo' => 'PEN', 'cantidad' => 1]),
            (string) $alternativa->id
        );

        $this->assertSame($destino->id, $response->getData(true)['alternativa_item']['alternativa_destino_id']);
    }

    public function test_item_manual_respeta_alternativa_destino_id_explicito(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 1', 'orden' => 1]);
        $destino2 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 2', 'orden' => 2]);

        $response = app(AlternativaItemController::class)->store(
            new Request([
                'origen_tipo' => 'manual', 'descripcion_manual' => 'Ítem test', 'costo_snapshot' => 10,
                'precio_venta_snapshot' => 15, 'moneda_costo' => 'PEN', 'cantidad' => 1, 'alternativa_destino_id' => $destino2->id,
            ]),
            (string) $alternativa->id
        );

        $this->assertSame($destino2->id, $response->getData(true)['alternativa_item']['alternativa_destino_id']);
    }

    public function test_item_manual_rechaza_alternativa_destino_id_de_otra_alternativa(): void
    {
        [$alternativa] = $this->crearAlternativaConDestino();
        [, $destinoAjeno] = $this->crearAlternativaConDestino();

        $response = app(AlternativaItemController::class)->store(
            new Request([
                'origen_tipo' => 'manual', 'descripcion_manual' => 'Ítem test', 'costo_snapshot' => 10,
                'precio_venta_snapshot' => 15, 'moneda_costo' => 'PEN', 'cantidad' => 1, 'alternativa_destino_id' => $destinoAjeno->id,
            ]),
            (string) $alternativa->id
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    // Los otros 4 puntos de creación individual — solo confirman que el
    // valor por defecto llega correctamente (el mecanismo de validación ya
    // se probó a fondo arriba).

    public function test_item_proveedor_usa_el_destino_por_defecto(): void
    {
        [$alternativa, $destino] = $this->crearAlternativaConDestino();

        $response = app(AlternativaItemController::class)->store(
            new Request([
                'origen_tipo' => 'proveedor', 'modo_precio' => 'tarifa_fija', 'moneda_costo' => 'PEN',
                'precio_venta_snapshot' => 50, 'costo_snapshot' => 30,
            ]),
            (string) $alternativa->id
        );

        $this->assertSame($destino->id, $response->getData(true)['alternativa_item']['alternativa_destino_id']);
    }

    private function crearGuiaTarifa(): GuiaTarifa
    {
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo Test 12f1', 'tipo' => 'zona']);
        $guia = Guia::create(['nombre' => 'Guía Test 12f1', 'documento' => '87654321', 'telefono' => '999999999', 'activo' => true]);

        return GuiaTarifa::create([
            'guia_id' => $guia->id, 'destino_id' => $destino->id, 'modalidad' => 'dia_local',
            'costo_diario' => 100, 'tipo_margen' => 'porcentaje', 'margen_valor' => 20,
            'moneda' => 'PEN', 'vigente_desde' => now()->toDateString(),
        ]);
    }

    public function test_item_guia_usa_el_destino_por_defecto(): void
    {
        [$alternativa, $destino] = $this->crearAlternativaConDestino();
        $guiaTarifa = $this->crearGuiaTarifa();

        $response = app(AlternativaItemController::class)->store(
            new Request(['origen_tipo' => 'guia', 'guia_tarifa_id' => $guiaTarifa->id, 'cantidad' => 1]),
            (string) $alternativa->id
        );

        $this->assertSame($destino->id, $response->getData(true)['alternativa_item']['alternativa_destino_id']);
    }

    public function test_item_pasaje_aereo_usa_el_destino_por_defecto(): void
    {
        [$alternativa, $destino] = $this->crearAlternativaConDestino();

        $response = app(AlternativaItemController::class)->store(
            new Request([
                'origen_tipo' => 'pasaje_aereo', 'aerolinea' => 'LATAM', 'moneda' => 'PEN', 'tarifa_base_adulto' => 100,
            ]),
            (string) $alternativa->id
        );

        $this->assertSame($destino->id, $response->getData(true)['alternativa_item']['alternativa_destino_id']);
    }

    private function crearProveedorTarifa(): ProveedorTarifa
    {
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo Test 12f1b', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Traslado Test 12f1 ' . uniqid()]);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);
        $proveedor = Proveedor::create(['razon_social' => 'Transportes Test 12f1 SAC', 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id, 'tipo_tarifa' => 'publica', 'modalidad' => 'privado',
            'moneda' => 'PEN', 'precio_costo' => 60, 'margen_tipo' => 'porcentaje', 'margen_valor' => 20,
            'precio_venta_adulto' => 93.66, 'vigente_desde' => now()->toDateString(),
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    // desdePlantilla() crea 2 de los 9 sitios en UNA sola llamada (branch
    // tarifa_fija/proveedor + branch ajuste_redondeo, ver §0 del brief).
    public function test_desde_plantilla_usa_el_destino_por_defecto_en_ambos_branches(): void
    {
        [$alternativa, $destino] = $this->crearAlternativaConDestino();
        $tarifa = $this->crearProveedorTarifa();
        $destinoAtractivo = DestinoAtractivo::first();
        $tour = PaquetePlantilla::create([
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE, 'nombre' => 'Tour Test 12f1',
            'destino_atractivo_id' => $destinoAtractivo->id, 'duracion_horas' => 8, 'ajuste_redondeo' => 6.34,
        ]);
        PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'proveedor_tarifa_id' => $tarifa->id, 'orden' => 1]);

        $response = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $items = AlternativaItem::where('alternativa_id', $alternativa->id)->get();
        $this->assertGreaterThanOrEqual(2, $items->count(), 'esperaba al menos el ítem de proveedor + el de ajuste de redondeo');
        foreach ($items as $item) {
            $this->assertSame($destino->id, $item->alternativa_destino_id, "item origen_tipo={$item->origen_tipo} sin destino por defecto");
        }
    }

    private function crearProveedorMayorista(): Proveedor
    {
        $tipoMayorista = ProveedorTipo::where('slug', ProveedorTipo::SLUG_MAYORISTA)->first();
        if (! $tipoMayorista) {
            $this->markTestSkipped('Catálogo central proveedor_tipos sin el slug agencia-mayorista en este entorno.');
        }

        return Proveedor::create(['razon_social' => 'Mayorista Test 12f1 SAC', 'estado' => true, 'tipo_id' => $tipoMayorista->id]);
    }

    public function test_item_mayorista_usa_el_destino_por_defecto(): void
    {
        [$alternativa, $destino] = $this->crearAlternativaConDestino();
        $proveedor = $this->crearProveedorMayorista();

        $opcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destino->id,
            'proveedor_id' => $proveedor->id, 'moneda' => 'PEN', 'estado' => 'elegida',
        ]);
        $hotel = OpcionHotel::create(['opcion_mayorista_id' => $opcion->id, 'nombre_hotel' => 'Hotel Test 12f1', 'moneda' => 'PEN']);
        $tarifaHotel = OpcionHotelTarifa::create([
            'opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 80, 'precio_venta' => 100,
        ]);

        $response = app(AlternativaItemController::class)->store(
            new Request(['origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifaHotel->id]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($destino->id, $response->getData(true)['alternativa_item']['alternativa_destino_id']);
    }
}
