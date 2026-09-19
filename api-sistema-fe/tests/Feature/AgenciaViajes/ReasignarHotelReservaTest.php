<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\Sale\Sale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Reasignar hotel Local/Nacional en una reserva ya aceptada, con
// auditoría — espejo de Sesion12hReasignarMayoristaTest.php (mayorista),
// aplicado al hotel de catálogo/ad-hoc. A diferencia de mayorista, un
// hotel puede venir de 2 caminos mutuamente excluyentes
// (proveedor_tarifa_id / opcion_hotel_tarifa_id) y la reasignación puede
// cruzar entre ellos — ver ReservaController::reasignarHotel().
class ReasignarHotelReservaTest extends TestCase
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

        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearTarifaHotelCatalogo(string $nombreHotel, string $tipoHabitacion, float $precioCosto, float $precioVenta): ProveedorTarifa
    {
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Zona Test Reasignar ' . random_int(1000, 9999), 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId(['nombre' => 'Hospedaje Test Reasignar', 'created_at' => now(), 'updated_at' => now()]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedor = Proveedor::create(['razon_social' => $nombreHotel, 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicioId]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id, 'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => $precioCosto, 'margen_tipo' => 'fijo', 'margen_valor' => $precioVenta - $precioCosto, 'precio_venta_adulto' => $precioVenta,
            'tipo_habitacion' => $tipoHabitacion, 'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    /**
     * Alternativa Local/Nacional con 1 ítem de hotel real del catálogo,
     * más una tarifa ad-hoc y una tarifa "no hotel" ya cargadas (para
     * los tests de reasignación cruzada / rechazo), reserva ya aceptada.
     */
    private function crearReservaConItemHotelCatalogo(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '77889900', 'full_name' => 'Cliente Test Reasignar Hotel',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-RH-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-11-01', 'fecha_viaje_hasta' => '2026-11-03',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa Reasignar Hotel', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $destino = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Tarapoto', 'orden' => 1]);

        $tarifaHotelA = $this->crearTarifaHotelCatalogo('Hotel Catálogo A', 'doble', 80, 120);
        $tarifaHotelB = $this->crearTarifaHotelCatalogo('Hotel Catálogo B', 'doble', 90, 130);

        $hotelAdhoc = OpcionHotel::create(['nombre_hotel' => 'Hotel Ad-hoc Test', 'moneda' => 'PEN']);
        $tarifaAdhoc = OpcionHotelTarifa::create(['opcion_hotel_id' => $hotelAdhoc->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 70, 'precio_venta' => 110]);

        $tarifaNoHotel = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $tarifaHotelA->proveedor_servicio_id, 'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => 10, 'margen_tipo' => 'fijo', 'margen_valor' => 5, 'precio_venta_adulto' => 15,
            'tipo_habitacion' => null, 'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destino->id,
            'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR, 'proveedor_tarifa_id' => $tarifaHotelA->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 80, 'precio_venta_snapshot' => 120, 'precio_convertido' => 120,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());
        $reservaItem = ReservaItem::where('alternativa_item_id', $item->id)->first();

        return compact('reserva', 'reservaItem', 'item', 'tarifaHotelA', 'tarifaHotelB', 'tarifaAdhoc', 'tarifaNoHotel', 'destino');
    }

    public function test_reasigna_de_catalogo_a_adhoc_y_conserva_el_original(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'tarifaHotelA' => $tarifaHotelA, 'tarifaAdhoc' => $tarifaAdhoc] = $this->crearReservaConItemHotelCatalogo();

        $this->assertSame($tarifaHotelA->id, $reservaItem->proveedor_tarifa_id);
        $this->assertNull($reservaItem->opcion_hotel_tarifa_id);

        $response = app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id],
            'nuevo_opcion_hotel_tarifa_id' => $tarifaAdhoc->id,
            'motivo' => 'Hotel A se quedó sin cupo.',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code']);

        $fresh = $reservaItem->fresh();
        $this->assertNull($fresh->proveedor_tarifa_id, 'el camino catálogo debe quedar en null tras cruzar a ad-hoc');
        $this->assertSame($tarifaAdhoc->id, $fresh->opcion_hotel_tarifa_id);
        $this->assertSame($tarifaHotelA->id, $fresh->proveedor_tarifa_original_id);
        $this->assertNull($fresh->opcion_hotel_tarifa_original_id, 'no tenía ad-hoc antes de esta reasignación');
        $this->assertSame('Hotel A se quedó sin cupo.', $fresh->motivo_reasignacion_hotel);
        $this->assertNotNull($fresh->fecha_reasignacion_hotel);
        $this->assertSame(1, $fresh->veces_reasignado_hotel);
    }

    public function test_reasigna_de_adhoc_a_catalogo_y_conserva_el_original(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'tarifaHotelB' => $tarifaHotelB, 'tarifaAdhoc' => $tarifaAdhoc] = $this->crearReservaConItemHotelCatalogo();

        // Arranca desde ad-hoc para probar el cruce en el sentido contrario.
        $reservaItem->update(['proveedor_tarifa_id' => null, 'opcion_hotel_tarifa_id' => $tarifaAdhoc->id]);

        app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id],
            'nueva_proveedor_tarifa_id' => $tarifaHotelB->id,
            'motivo' => 'El ad-hoc en realidad ya está en el catálogo.',
        ]), (string) $reserva->id);

        $fresh = $reservaItem->fresh();
        $this->assertSame($tarifaHotelB->id, $fresh->proveedor_tarifa_id);
        $this->assertNull($fresh->opcion_hotel_tarifa_id);
        $this->assertNull($fresh->proveedor_tarifa_original_id, 'no tenía catálogo antes de esta reasignación');
        $this->assertSame($tarifaAdhoc->id, $fresh->opcion_hotel_tarifa_original_id);
    }

    // El caso que motivó NO copiar el patrón `?? valor_actual` de
    // reasignarMayorista() tal cual: catálogo A → ad-hoc → catálogo B. Si
    // "original" se recalculara por columna en cada paso, la 2da vuelta
    // pisaría proveedor_tarifa_original_id (ya en null tras el paso 1) con
    // el id de A otra vez por casualidad, pero opcion_hotel_tarifa_original_id
    // se pisaría con el ad-hoc INTERMEDIO en vez de quedar null — el
    // original real (A, catálogo) se perdería.
    public function test_segunda_reasignacion_cruzando_de_vuelta_no_pisa_el_original_real(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'tarifaHotelA' => $tarifaHotelA, 'tarifaHotelB' => $tarifaHotelB, 'tarifaAdhoc' => $tarifaAdhoc] = $this->crearReservaConItemHotelCatalogo();

        app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nuevo_opcion_hotel_tarifa_id' => $tarifaAdhoc->id, 'motivo' => 'Primera reasignación',
        ]), (string) $reserva->id);

        app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_proveedor_tarifa_id' => $tarifaHotelB->id, 'motivo' => 'Segunda reasignación',
        ]), (string) $reserva->id);

        $fresh = $reservaItem->fresh();
        $this->assertSame($tarifaHotelB->id, $fresh->proveedor_tarifa_id);
        $this->assertNull($fresh->opcion_hotel_tarifa_id);
        $this->assertSame($tarifaHotelA->id, $fresh->proveedor_tarifa_original_id, 'el original real (A) no debe perderse tras cruzar 2 veces');
        $this->assertNull($fresh->opcion_hotel_tarifa_original_id, 'nunca tuvo ad-hoc como origen real');
        $this->assertSame(2, $fresh->veces_reasignado_hotel);
    }

    public function test_calcula_costo_anterior_y_nuevo(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'tarifaAdhoc' => $tarifaAdhoc] = $this->crearReservaConItemHotelCatalogo();

        $response = app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nuevo_opcion_hotel_tarifa_id' => $tarifaAdhoc->id, 'motivo' => 'Con diferencia de costo',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertEquals(80.0, $body['costo_anterior']);
        $this->assertEquals(70.0, $body['costo_nuevo']);
    }

    public function test_rechaza_cuando_no_llega_ningun_hotel_nuevo(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem] = $this->crearReservaConItemHotelCatalogo();

        $response = app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'motivo' => 'Sin destino',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_rechaza_cuando_llegan_ambos_destinos_a_la_vez(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'tarifaHotelB' => $tarifaHotelB, 'tarifaAdhoc' => $tarifaAdhoc] = $this->crearReservaConItemHotelCatalogo();

        $response = app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id],
            'nueva_proveedor_tarifa_id' => $tarifaHotelB->id, 'nuevo_opcion_hotel_tarifa_id' => $tarifaAdhoc->id,
            'motivo' => 'Ambos a la vez',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('exactamente un hotel', $response->getData(true)['message']);
    }

    public function test_rechaza_tarifa_de_catalogo_que_no_es_de_habitacion(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'tarifaNoHotel' => $tarifaNoHotel] = $this->crearReservaConItemHotelCatalogo();

        $response = app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_proveedor_tarifa_id' => $tarifaNoHotel, 'motivo' => 'Tarifa equivocada',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('habitación de hotel', $response->getData(true)['message']);
    }

    public function test_rechaza_reserva_no_activa(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'tarifaAdhoc' => $tarifaAdhoc] = $this->crearReservaConItemHotelCatalogo();
        $reserva->update(['estado' => 'cancelada']);

        $response = app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nuevo_opcion_hotel_tarifa_id' => $tarifaAdhoc->id, 'motivo' => 'No debería aplicar',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_rechaza_item_ya_facturado(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'tarifaAdhoc' => $tarifaAdhoc] = $this->crearReservaConItemHotelCatalogo();

        ReservaVenta::create([
            'reserva_id' => $reserva->id, 'sale_id' => Sale::factory()->create()->id,
            'reserva_item_ids' => [$reservaItem->id], 'reserva_pasajero_ids' => [],
        ]);

        $response = app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nuevo_opcion_hotel_tarifa_id' => $tarifaAdhoc->id, 'motivo' => 'Ya facturado',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('facturado', $response->getData(true)['message']);
    }

    public function test_rechaza_lote_con_hoteles_actuales_mezclados(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'item' => $item, 'tarifaHotelB' => $tarifaHotelB, 'tarifaAdhoc' => $tarifaAdhoc, 'destino' => $destino] = $this->crearReservaConItemHotelCatalogo();

        $item2 = AlternativaItem::create([
            'alternativa_id' => $item->alternativa_id, 'alternativa_destino_id' => $destino->id,
            'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR, 'proveedor_tarifa_id' => $tarifaHotelB->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 90, 'precio_venta_snapshot' => 130, 'precio_convertido' => 130,
        ]);
        $reservaItem2 = ReservaItem::create([
            'reserva_id' => $reserva->id, 'alternativa_item_id' => $item2->id, 'proveedor_tarifa_id' => $tarifaHotelB->id,
        ]);

        $response = app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id, $reservaItem2->id], 'nuevo_opcion_hotel_tarifa_id' => $tarifaAdhoc->id, 'motivo' => 'Lote mezclado',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('mismo hotel actual', $response->getData(true)['message']);
    }

    public function test_exige_motivo(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'tarifaAdhoc' => $tarifaAdhoc] = $this->crearReservaConItemHotelCatalogo();

        $response = app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nuevo_opcion_hotel_tarifa_id' => $tarifaAdhoc->id,
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    // Hallazgo real en vivo (18-sep-2026, Playwright contra agencia-demo):
    // la reasignación se guardaba bien en la BD, pero "Resumen de la
    // reserva" seguía mostrando el hotel VIEJO — resolverNombreItem()
    // leía item->proveedorTarifa (el AlternativaItem original, que
    // reasignarHotel() nunca toca) en vez de reservaItem->proveedorTarifa.
    // Mismo bug que mayorista ya tuvo antes de 12h, nunca portado al
    // camino de hotel de catálogo hasta este fix.
    public function test_resumen_de_la_reserva_refleja_el_hotel_reasignado(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'tarifaHotelB' => $tarifaHotelB] = $this->crearReservaConItemHotelCatalogo();

        app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_proveedor_tarifa_id' => $tarifaHotelB->id, 'motivo' => 'Test resumen',
        ]), (string) $reserva->id);

        // show() exige un usuario autenticado (Fase 1b §3.3).
        $role = Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super-Admin']);
        $admin = User::factory()->create();
        $admin->assignRole($role);
        Auth::guard('api')->setUser($admin->fresh());

        $response = app(ReservaController::class)->show((string) $reserva->id);
        $body = $response->getData(true);

        $entrada = collect($body['resumen'])->firstWhere('reserva_item_id', $reservaItem->id);
        $this->assertNotNull($entrada);
        $this->assertStringContainsString('Hotel Catálogo B', $entrada['nombre']);
        $this->assertStringNotContainsString('Hotel Catálogo A', $entrada['nombre']);
    }

    public function test_no_toca_precio_venta_snapshot_del_item(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'item' => $item, 'tarifaAdhoc' => $tarifaAdhoc] = $this->crearReservaConItemHotelCatalogo();

        $precioAntes = (float) $item->precio_venta_snapshot;

        app(ReservaController::class)->reasignarHotel(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nuevo_opcion_hotel_tarifa_id' => $tarifaAdhoc->id, 'motivo' => 'Test regresión precio',
        ]), (string) $reserva->id);

        $this->assertSame($precioAntes, (float) $item->fresh()->precio_venta_snapshot);
    }
}
