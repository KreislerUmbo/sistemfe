<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaPasajeroController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fase D del plan "Proceso de reserva: facturación + 3 fixes" (2026-08-19)
// — cierra el gap real de que no había forma de quitar un ítem o un
// pasajero de una reserva ya aceptada (sincronizar-items solo agregaba).
// Mismo patrón de infraestructura que el resto del módulo: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class ReservaQuitarItemsPasajerosTest extends TestCase
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

        // users.role_id tiene default(1) a nivel de Postgres
        // (users_role_id_foreign) — Sale::factory() crea un User::factory()
        // de paso, que revienta con FK violation sin esto (mismo fixture
        // que SaleControllerSerieComprobanteTest/ReservarCorrelativoTest).
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

    /** Reserva activa con 2 pasajeros y 2 ítems (cada ítem asignado a un pasajero distinto). */
    private function crearReservaConDosPasajerosYDosItems(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '66778899', 'full_name' => 'Cliente Test Quitar',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0500-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('cotizacion_pasajeros')->insert([
            ['cotizacion_id' => $cotizacionId, 'edad' => 30, 'tipo_pax' => 'adulto', 'created_at' => now(), 'updated_at' => now()],
            ['cotizacion_id' => $cotizacionId, 'edad' => 28, 'tipo_pax' => 'adulto', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        $item1 = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Ítem 1', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);
        $item2 = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Ítem 2', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $reservaItem1 = ReservaItem::where('alternativa_item_id', $item1->id)->first();
        $reservaItem2 = ReservaItem::where('alternativa_item_id', $item2->id)->first();
        $pasajeros = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get();

        ReservaItemPasajero::create(['reserva_item_id' => $reservaItem1->id, 'reserva_pasajero_id' => $pasajeros[0]->id]);
        ReservaItemPasajero::create(['reserva_item_id' => $reservaItem2->id, 'reserva_pasajero_id' => $pasajeros[1]->id]);

        return [$reserva, $reservaItem1, $reservaItem2, $pasajeros[0], $pasajeros[1]];
    }

    // ── reserva-items ────────────────────────────────────────────────────

    public function test_quitar_item_exitoso_limpia_asignaciones_de_pasajero(): void
    {
        [, $reservaItem1] = $this->crearReservaConDosPasajerosYDosItems();

        $response = app(ReservaItemController::class)->destroy((string) $reservaItem1->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(ReservaItem::where('id', $reservaItem1->id)->exists());
        $this->assertSame(0, ReservaItemPasajero::where('reserva_item_id', $reservaItem1->id)->count());
    }

    public function test_quitar_item_rechaza_si_es_el_ultimo(): void
    {
        [$reserva, $reservaItem1, $reservaItem2] = $this->crearReservaConDosPasajerosYDosItems();

        app(ReservaItemController::class)->destroy((string) $reservaItem1->id);
        $this->assertSame(1, $reserva->items()->count());

        $response = app(ReservaItemController::class)->destroy((string) $reservaItem2->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue(ReservaItem::where('id', $reservaItem2->id)->exists());
    }

    public function test_quitar_item_rechaza_si_reserva_no_activa(): void
    {
        [$reserva, $reservaItem1] = $this->crearReservaConDosPasajerosYDosItems();
        $reserva->update(['estado' => 'cancelada']);

        $response = app(ReservaItemController::class)->destroy((string) $reservaItem1->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue(ReservaItem::where('id', $reservaItem1->id)->exists());
    }

    public function test_quitar_item_rechaza_si_ya_facturado(): void
    {
        [$reserva, $reservaItem1] = $this->crearReservaConDosPasajerosYDosItems();

        ReservaVenta::create([
            'reserva_id' => $reserva->id, 'sale_id' => \App\Models\Sale\Sale::factory()->create()->id,
            'reserva_item_ids' => [$reservaItem1->id], 'reserva_pasajero_ids' => [],
        ]);

        $response = app(ReservaItemController::class)->destroy((string) $reservaItem1->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('facturado', $response->getData(true)['message']);
        $this->assertTrue(ReservaItem::where('id', $reservaItem1->id)->exists());
    }

    // ── reserva-pasajeros ────────────────────────────────────────────────

    public function test_quitar_pasajero_exitoso_limpia_asignaciones_de_item(): void
    {
        [, , , $pasajero1] = $this->crearReservaConDosPasajerosYDosItems();

        $response = app(ReservaPasajeroController::class)->destroy((string) $pasajero1->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(ReservaPasajero::where('id', $pasajero1->id)->exists());
        $this->assertSame(0, ReservaItemPasajero::where('reserva_pasajero_id', $pasajero1->id)->count());
    }

    public function test_quitar_pasajero_rechaza_si_es_el_ultimo(): void
    {
        [$reserva, , , $pasajero1, $pasajero2] = $this->crearReservaConDosPasajerosYDosItems();

        app(ReservaPasajeroController::class)->destroy((string) $pasajero1->id);
        $this->assertSame(1, $reserva->pasajeros()->count());

        $response = app(ReservaPasajeroController::class)->destroy((string) $pasajero2->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue(ReservaPasajero::where('id', $pasajero2->id)->exists());
    }

    public function test_quitar_pasajero_rechaza_si_reserva_no_activa(): void
    {
        [$reserva, , , $pasajero1] = $this->crearReservaConDosPasajerosYDosItems();
        $reserva->update(['estado' => 'cancelada']);

        $response = app(ReservaPasajeroController::class)->destroy((string) $pasajero1->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue(ReservaPasajero::where('id', $pasajero1->id)->exists());
    }

    public function test_quitar_pasajero_rechaza_si_reserva_ya_facturada(): void
    {
        [$reserva, , , $pasajero1] = $this->crearReservaConDosPasajerosYDosItems();

        ReservaVenta::create([
            'reserva_id' => $reserva->id, 'sale_id' => \App\Models\Sale\Sale::factory()->create()->id,
            'reserva_item_ids' => [], 'reserva_pasajero_ids' => [$pasajero1->id],
        ]);

        $response = app(ReservaPasajeroController::class)->destroy((string) $pasajero1->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue(ReservaPasajero::where('id', $pasajero1->id)->exists());
    }
}
