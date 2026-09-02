<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\Sale\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 12h — brief PEGAR-EN-CLAUDE-CODE-reasignar-mayorista-vivo.md
// (auditoria-arquitectonica-agencia-viajes.md §9.2). Mismo patrón de
// infraestructura que ReservaReprogramarTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class Sesion12hReasignarMayoristaTest extends TestCase
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

        // users.role_id tiene default(1) a nivel de Postgres — Sale::factory()
        // crea un User::factory() de paso, revienta con FK violation sin esto
        // (mismo fixture que el resto de la suite AgenciaViajes).
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

    private function crearProveedorMayorista(string $nombre): Proveedor
    {
        $tipoMayorista = ProveedorTipo::where('slug', ProveedorTipo::SLUG_MAYORISTA)->first();
        if (! $tipoMayorista) {
            $this->markTestSkipped('Catálogo central proveedor_tipos sin el slug agencia-mayorista en este entorno.');
        }

        return Proveedor::create(['razon_social' => $nombre, 'estado' => true, 'tipo_id' => $tipoMayorista->id]);
    }

    /**
     * Arma una alternativa con 1 destino y 2 OpcionMayorista (misma
     * alternativa_destino_id, ambas con 1 tarifa de hotel), un item de
     * origen mayorista colgado de la primera, y la reserva ya aceptada
     * (crearReservaDesdeAlternativa, mismo helper que ReservaReprogramarTest).
     */
    private function crearReservaConItemMayorista(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '33445566', 'full_name' => 'Cliente Test 12h',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-12H-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Cancún', 'fecha_viaje_desde' => '2026-11-01', 'fecha_viaje_hasta' => '2026-11-05',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa México', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        $destino = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Cancún', 'orden' => 1]);

        $mayoristaA = $this->crearProveedorMayorista('Mayorista A SAC');
        $mayoristaB = $this->crearProveedorMayorista('Mayorista B SAC');

        $opcionA = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destino->id,
            'proveedor_id' => $mayoristaA->id, 'moneda' => 'USD', 'estado' => 'elegida',
        ]);
        $opcionB = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destino->id,
            'proveedor_id' => $mayoristaB->id, 'moneda' => 'USD', 'estado' => 'candidata',
        ]);

        $hotelA = OpcionHotel::create(['opcion_mayorista_id' => $opcionA->id, 'nombre_hotel' => 'Hotel A', 'moneda' => 'USD']);
        $tarifaA = OpcionHotelTarifa::create(['opcion_hotel_id' => $hotelA->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 100, 'precio_venta' => 150]);

        $hotelB = OpcionHotel::create(['opcion_mayorista_id' => $opcionB->id, 'nombre_hotel' => 'Hotel B', 'moneda' => 'USD']);
        $tarifaB = OpcionHotelTarifa::create(['opcion_hotel_id' => $hotelB->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 130, 'precio_venta' => 190]);

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destino->id,
            'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA, 'opcion_mayorista_id' => $opcionA->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD',
            'costo_snapshot' => 100, 'precio_venta_snapshot' => 150, 'precio_convertido' => 150,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());
        $reservaItem = ReservaItem::where('alternativa_item_id', $item->id)->first();

        return compact('reserva', 'reservaItem', 'item', 'opcionA', 'opcionB', 'tarifaB', 'destino');
    }

    public function test_reasigna_mayorista_y_conserva_el_original(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'opcionA' => $opcionA, 'opcionB' => $opcionB] = $this->crearReservaConItemMayorista();

        $this->assertSame($opcionA->id, $reservaItem->opcion_mayorista_id, 'la reserva debe nacer con el mayorista de la alternativa aceptada');

        $response = app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$reservaItem->id],
            'nueva_opcion_mayorista_id' => $opcionB->id,
            'motivo' => 'Mayorista A no confirmó cupo.',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code']);

        $fresh = $reservaItem->fresh();
        $this->assertSame($opcionB->id, $fresh->opcion_mayorista_id);
        $this->assertSame($opcionA->id, $fresh->opcion_mayorista_original_id);
        $this->assertSame('Mayorista A no confirmó cupo.', $fresh->motivo_reasignacion_mayorista);
        $this->assertNotNull($fresh->fecha_reasignacion_mayorista);
        $this->assertSame(1, $fresh->veces_reasignado_mayorista);
    }

    public function test_segunda_reasignacion_no_pisa_el_original(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'opcionA' => $opcionA, 'opcionB' => $opcionB] = $this->crearReservaConItemMayorista();

        $destino = AlternativaDestino::find($opcionA->alternativa_destino_id);
        $mayoristaC = $this->crearProveedorMayorista('Mayorista C SAC');
        $opcionC = OpcionMayorista::create([
            'alternativa_id' => $opcionA->alternativa_id, 'alternativa_destino_id' => $destino->id,
            'proveedor_id' => $mayoristaC->id, 'moneda' => 'USD', 'estado' => 'candidata',
        ]);

        app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_opcion_mayorista_id' => $opcionB->id, 'motivo' => 'Primera reasignación',
        ]), (string) $reserva->id);

        app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_opcion_mayorista_id' => $opcionC->id, 'motivo' => 'Segunda reasignación',
        ]), (string) $reserva->id);

        $fresh = $reservaItem->fresh();
        $this->assertSame($opcionC->id, $fresh->opcion_mayorista_id);
        // El original sigue siendo A — no se pisó con B en la segunda vuelta.
        $this->assertSame($opcionA->id, $fresh->opcion_mayorista_original_id);
        $this->assertSame('Segunda reasignación', $fresh->motivo_reasignacion_mayorista);
        $this->assertSame(2, $fresh->veces_reasignado_mayorista);
    }

    public function test_no_toca_precio_venta_snapshot_del_item_ni_costo_de_la_alternativa(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'item' => $item, 'opcionB' => $opcionB] = $this->crearReservaConItemMayorista();

        $precioAntes = (float) $item->precio_venta_snapshot;
        $costoAntes = (float) $item->costo_snapshot;

        app(ReservaController::class)->reasignarMayorista(new Request([
            // Mayorista B cuesta más (tarifaB precio_costo=130 vs 100 de A) —
            // el snapshot del cliente no debe moverse ni un centavo.
            'reserva_item_ids' => [$reservaItem->id], 'nueva_opcion_mayorista_id' => $opcionB->id, 'motivo' => 'Test regresión precio',
        ]), (string) $reserva->id);

        $itemFresh = $item->fresh();
        $this->assertSame($precioAntes, (float) $itemFresh->precio_venta_snapshot);
        $this->assertSame($costoAntes, (float) $itemFresh->costo_snapshot);
    }

    public function test_calcula_costo_anterior_y_nuevo_cuando_llega_tarifa_nueva(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'opcionB' => $opcionB, 'tarifaB' => $tarifaB] = $this->crearReservaConItemMayorista();

        $response = app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_opcion_mayorista_id' => $opcionB->id,
            'nueva_opcion_hotel_tarifa_id' => $tarifaB->id, 'motivo' => 'Con tarifa nueva',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertEquals(100.0, $body['costo_anterior']);
        $this->assertEquals(130.0, $body['costo_nuevo']);
    }

    public function test_costo_nuevo_es_null_sin_tarifa_nueva(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'opcionB' => $opcionB] = $this->crearReservaConItemMayorista();

        $response = app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_opcion_mayorista_id' => $opcionB->id, 'motivo' => 'Sin tarifa nueva',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertEquals(100.0, $body['costo_anterior']);
        $this->assertNull($body['costo_nuevo']);
    }

    public function test_rechaza_lote_con_mayoristas_actuales_mezclados(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'item' => $item, 'opcionA' => $opcionA, 'opcionB' => $opcionB, 'destino' => $destino] = $this->crearReservaConItemMayorista();

        // Un segundo ítem de la MISMA reserva, colgado del mayorista B
        // desde el vamos (mezcla real, no forzada).
        $item2 = AlternativaItem::create([
            'alternativa_id' => $item->alternativa_id, 'alternativa_destino_id' => $destino->id,
            'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA, 'opcion_mayorista_id' => $opcionB->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD',
            'costo_snapshot' => 130, 'precio_venta_snapshot' => 190, 'precio_convertido' => 190,
        ]);
        $reservaItem2 = ReservaItem::create([
            'reserva_id' => $reserva->id, 'alternativa_item_id' => $item2->id, 'opcion_mayorista_id' => $opcionB->id,
        ]);

        $mayoristaC = $this->crearProveedorMayorista('Mayorista C SAC');
        $opcionC = OpcionMayorista::create([
            'alternativa_id' => $opcionA->alternativa_id, 'alternativa_destino_id' => $destino->id,
            'proveedor_id' => $mayoristaC->id, 'moneda' => 'USD', 'estado' => 'candidata',
        ]);

        $response = app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$reservaItem->id, $reservaItem2->id], 'nueva_opcion_mayorista_id' => $opcionC->id, 'motivo' => 'Lote mezclado',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('mismo mayorista actual', $response->getData(true)['message']);
    }

    public function test_rechaza_nueva_opcion_de_otro_destino(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'item' => $item] = $this->crearReservaConItemMayorista();

        // Segundo destino de la MISMA alternativa, con su propio mayorista —
        // no se puede reasignar a un mayorista de otro destino del viaje.
        $otroDestino = AlternativaDestino::create(['alternativa_id' => $item->alternativa_id, 'destino_texto' => 'Lima', 'orden' => 2]);
        $mayoristaAjeno = $this->crearProveedorMayorista('Mayorista Ajeno SAC');
        $opcionAjena = OpcionMayorista::create([
            'alternativa_id' => $item->alternativa_id, 'alternativa_destino_id' => $otroDestino->id,
            'proveedor_id' => $mayoristaAjeno->id, 'moneda' => 'USD', 'estado' => 'candidata',
        ]);

        $response = app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_opcion_mayorista_id' => $opcionAjena->id, 'motivo' => 'Destino equivocado',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('mismo destino', $response->getData(true)['message']);
    }

    public function test_rechaza_reserva_no_activa(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'opcionB' => $opcionB] = $this->crearReservaConItemMayorista();
        $reserva->update(['estado' => 'cancelada']);

        $response = app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_opcion_mayorista_id' => $opcionB->id, 'motivo' => 'No debería aplicar',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_exige_motivo(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'opcionB' => $opcionB] = $this->crearReservaConItemMayorista();

        $response = app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_opcion_mayorista_id' => $opcionB->id,
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_rechaza_item_ya_facturado(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'opcionB' => $opcionB] = $this->crearReservaConItemMayorista();

        ReservaVenta::create([
            'reserva_id' => $reserva->id, 'sale_id' => Sale::factory()->create()->id,
            'reserva_item_ids' => [$reservaItem->id], 'reserva_pasajero_ids' => [],
        ]);

        $response = app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_opcion_mayorista_id' => $opcionB->id, 'motivo' => 'Ya facturado',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('facturado', $response->getData(true)['message']);
    }

    // Hallazgo real de esta sesión (verificación en vivo contra
    // agencia-demo): resolverNombreItem() solo recibía AlternativaItem, así
    // que el panel "Resumen de la reserva" seguía mostrando el mayorista
    // ORIGINAL después de reasignar — el ítem sí se movía en la BD, pero
    // nada en la respuesta de la reserva lo reflejaba. Corregido con un
    // segundo parámetro opcional ReservaItem.
    public function test_resumen_de_la_reserva_refleja_el_mayorista_reasignado(): void
    {
        ['reserva' => $reserva, 'reservaItem' => $reservaItem, 'opcionB' => $opcionB] = $this->crearReservaConItemMayorista();

        app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$reservaItem->id], 'nueva_opcion_mayorista_id' => $opcionB->id, 'motivo' => 'Test resumen',
        ]), (string) $reserva->id);

        $response = app(ReservaController::class)->show((string) $reserva->id);
        $body = $response->getData(true);

        $entrada = collect($body['resumen'])->firstWhere('reserva_item_id', $reservaItem->id);
        $this->assertNotNull($entrada);
        $this->assertSame('Mayorista B SAC', $entrada['nombre']);
    }

    public function test_rechaza_item_de_otra_reserva(): void
    {
        ['reserva' => $reserva, 'opcionB' => $opcionB] = $this->crearReservaConItemMayorista();
        ['reservaItem' => $itemDeOtraReserva] = $this->crearReservaConItemMayorista();

        $response = app(ReservaController::class)->reasignarMayorista(new Request([
            'reserva_item_ids' => [$itemDeOtraReserva->id], 'nueva_opcion_mayorista_id' => $opcionB->id, 'motivo' => 'Ítem ajeno',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
    }
}
