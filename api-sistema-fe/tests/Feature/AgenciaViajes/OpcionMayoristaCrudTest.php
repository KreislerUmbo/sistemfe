<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\OpcionHotelController;
use App\Http\Controllers\AgenciaViajes\OpcionMayoristaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;
use App\Models\AgenciaViajes\OpcionMayoristaTour;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// CRUD real (editar/eliminar) para hoteles/opcionales/tours de una
// OpcionMayorista, más el borrado del bloque completo — pedido explícito
// del usuario, 04-sep-2026, después de que solo existiera alta para estos
// 3 sub-recursos (el mismo problema que ya se había resuelto una vez para
// la propia tarjeta de OpcionMayorista). Mismo patrón de infraestructura
// que el resto de la suite: Postgres real (sistemafe_test_migrations),
// transacción por test revertida.
class OpcionMayoristaCrudTest extends TestCase
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

    private function crearAlternativa(string $estado = 'borrador'): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '44556677', 'full_name' => 'Cliente Test CRUD Mayorista',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-CRUDM-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Panamá', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa CRUD Mayorista', 'estado' => $estado,
            'moneda_cotizacion' => 'USD', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function crearProveedorMayorista(): Proveedor
    {
        $tipoMayorista = ProveedorTipo::where('slug', ProveedorTipo::SLUG_MAYORISTA)->first();
        if (! $tipoMayorista) {
            $this->markTestSkipped('Catálogo central proveedor_tipos sin el slug agencia-mayorista en este entorno.');
        }

        return Proveedor::create(['razon_social' => 'Mayorista Test CRUD SAC', 'estado' => true, 'tipo_id' => $tipoMayorista->id]);
    }

    private function crearOpcionConHotelYTarifa(Alternativa $alternativa): array
    {
        $opcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $this->crearProveedorMayorista()->id,
            'moneda' => 'USD', 'estado' => 'elegida',
        ]);
        $hotel = OpcionHotel::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre_hotel' => 'Hotel Test CRUD', 'moneda' => 'USD',
        ]);
        $tarifa = OpcionHotelTarifa::create([
            'opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'matrimonial', 'precio_costo' => 800, 'precio_venta' => 880,
        ]);

        return [$opcion, $hotel, $tarifa];
    }

    private function crearItemDesdeTarifa(Alternativa $alternativa, OpcionMayorista $opcion, OpcionHotelTarifa $tarifa): AlternativaItem
    {
        return AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA,
            'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 2, 'moneda_costo' => 'USD',
            'costo_snapshot' => $tarifa->precio_costo, 'precio_venta_snapshot' => $tarifa->precio_venta, 'precio_convertido' => $tarifa->precio_venta,
        ]);
    }

    // ── OpcionMayoristaController::eliminar() — bloque completo ──────────

    public function test_eliminar_bloque_rechaza_si_alternativa_ya_aceptada(): void
    {
        $alternativa = $this->crearAlternativa('aceptada');
        [$opcion] = $this->crearOpcionConHotelYTarifa($alternativa);

        $response = app(OpcionMayoristaController::class)->eliminar((string) $opcion->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotNull(OpcionMayorista::find($opcion->id));
    }

    public function test_eliminar_bloque_rechaza_si_algun_item_ya_tiene_reserva(): void
    {
        $alternativa = $this->crearAlternativa();
        [$opcion, , $tarifa] = $this->crearOpcionConHotelYTarifa($alternativa);
        $item = $this->crearItemDesdeTarifa($alternativa, $opcion, $tarifa);
        $reserva = Reserva::create(['alternativa_id' => $alternativa->id, 'estado' => 'activa']);
        ReservaItem::create(['reserva_id' => $reserva->id, 'alternativa_item_id' => $item->id]);

        $response = app(OpcionMayoristaController::class)->eliminar((string) $opcion->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotNull(OpcionMayorista::find($opcion->id));
        $this->assertNotNull(AlternativaItem::find($item->id));
    }

    public function test_eliminar_bloque_cascada_completa_y_recalcula_total(): void
    {
        $alternativa = $this->crearAlternativa();
        [$opcion, $hotel, $tarifa] = $this->crearOpcionConHotelYTarifa($alternativa);
        $item = $this->crearItemDesdeTarifa($alternativa, $opcion, $tarifa);
        $opcional = OpcionMayoristaOpcional::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre' => 'Opcional Test', 'precio_por_persona' => 50, 'moneda' => 'USD',
        ]);
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Panamá Test CRUD', 'tipo' => 'zona', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tour = PaquetePlantilla::create([
            'categoria' => 'internacional', 'tipo' => 'tour_simple', 'nombre' => 'Tour Test CRUD',
            'destino_atractivo_id' => $destinoAtractivoId, 'duracion_horas' => 8,
        ]);
        $tourLink = OpcionMayoristaTour::create(['opcion_mayorista_id' => $opcion->id, 'paquete_plantilla_id' => $tour->id, 'orden' => 1]);
        $alternativa->update(['total' => 1760]);

        $response = app(OpcionMayoristaController::class)->eliminar((string) $opcion->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(OpcionMayorista::find($opcion->id));
        $this->assertNull(OpcionHotel::find($hotel->id));
        $this->assertNull(OpcionHotelTarifa::find($tarifa->id));
        $this->assertNull(OpcionMayoristaOpcional::find($opcional->id));
        $this->assertNull(OpcionMayoristaTour::find($tourLink->id));
        $this->assertNull(AlternativaItem::find($item->id));
        // El PaquetePlantilla real del tour NUNCA se borra — solo el vínculo.
        $this->assertNotNull(PaquetePlantilla::find($tour->id));
        $this->assertEquals(0, (float) $alternativa->fresh()->total);
    }

    // ── OpcionHotelController::update()/destroy() ────────────────────────

    public function test_actualizar_hotel_no_toca_el_snapshot_de_un_item_ya_agregado(): void
    {
        $alternativa = $this->crearAlternativa();
        [$opcion, $hotel, $tarifa] = $this->crearOpcionConHotelYTarifa($alternativa);
        $item = $this->crearItemDesdeTarifa($alternativa, $opcion, $tarifa);

        $response = app(OpcionHotelController::class)->update(new Request(['nombre_hotel' => 'Hotel Corregido']), (string) $hotel->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Hotel Corregido', $hotel->fresh()->nombre_hotel);
        $this->assertEquals(880, (float) $item->fresh()->precio_venta_snapshot, 'el snapshot ya congelado del item no cambia');
    }

    public function test_eliminar_hotel_rechaza_si_tiene_reserva_generada(): void
    {
        $alternativa = $this->crearAlternativa();
        [$opcion, $hotel, $tarifa] = $this->crearOpcionConHotelYTarifa($alternativa);
        $item = $this->crearItemDesdeTarifa($alternativa, $opcion, $tarifa);
        $reserva = Reserva::create(['alternativa_id' => $alternativa->id, 'estado' => 'activa']);
        ReservaItem::create(['reserva_id' => $reserva->id, 'alternativa_item_id' => $item->id]);

        $response = app(OpcionHotelController::class)->destroy((string) $hotel->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotNull(OpcionHotel::find($hotel->id));
    }

    public function test_eliminar_hotel_sin_reserva_cascada_a_sus_tarifas_e_items(): void
    {
        $alternativa = $this->crearAlternativa();
        [, $hotel, $tarifa] = $this->crearOpcionConHotelYTarifa($alternativa);

        $response = app(OpcionHotelController::class)->destroy((string) $hotel->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(OpcionHotel::find($hotel->id));
        $this->assertNull(OpcionHotelTarifa::find($tarifa->id));
    }

    public function test_agregar_tarifa_a_hotel_ya_creado(): void
    {
        $alternativa = $this->crearAlternativa();
        [, $hotel] = $this->crearOpcionConHotelYTarifa($alternativa);

        $response = app(OpcionHotelController::class)->agregarTarifa(new Request([
            'tipo_habitacion' => 'simple', 'precio_costo' => 400, 'precio_venta' => 450,
        ]), (string) $hotel->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, OpcionHotelTarifa::where('opcion_hotel_id', $hotel->id)->count());
    }

    // ── OpcionMayoristaController::actualizarOpcional()/eliminarOpcional() ──

    public function test_actualizar_y_eliminar_opcional(): void
    {
        $alternativa = $this->crearAlternativa();
        [$opcion] = $this->crearOpcionConHotelYTarifa($alternativa);
        $opcional = OpcionMayoristaOpcional::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre' => 'Original', 'precio_por_persona' => 50, 'moneda' => 'USD',
        ]);

        $resUpdate = app(OpcionMayoristaController::class)->actualizarOpcional(new Request([
            'nombre' => 'Corregido', 'precio_por_persona' => 60, 'moneda' => 'USD',
        ]), (string) $opcional->id);
        $this->assertSame(200, $resUpdate->getStatusCode());
        $this->assertSame('Corregido', $opcional->fresh()->nombre);
        $this->assertEquals(60, (float) $opcional->fresh()->precio_por_persona);

        $resDelete = app(OpcionMayoristaController::class)->eliminarOpcional((string) $opcional->id);
        $this->assertSame(200, $resDelete->getStatusCode());
        $this->assertNull(OpcionMayoristaOpcional::find($opcional->id));
    }

    // ── OpcionMayoristaController::actualizarOrdenTour() ─────────────────

    public function test_actualizar_orden_tour(): void
    {
        $alternativa = $this->crearAlternativa();
        [$opcion] = $this->crearOpcionConHotelYTarifa($alternativa);
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Panamá Test Orden', 'tipo' => 'zona', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tour = PaquetePlantilla::create([
            'categoria' => 'internacional', 'tipo' => 'tour_simple', 'nombre' => 'Tour Test Orden',
            'destino_atractivo_id' => $destinoAtractivoId, 'duracion_horas' => 8,
        ]);
        $tourLink = OpcionMayoristaTour::create(['opcion_mayorista_id' => $opcion->id, 'paquete_plantilla_id' => $tour->id, 'orden' => 1]);

        $response = app(OpcionMayoristaController::class)->actualizarOrdenTour(new Request(['orden' => 3]), (string) $tourLink->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $tourLink->fresh()->orden);
        // El PaquetePlantilla real nunca se toca por este endpoint.
        $this->assertSame('Tour Test Orden', $tour->fresh()->nombre);
    }
}
