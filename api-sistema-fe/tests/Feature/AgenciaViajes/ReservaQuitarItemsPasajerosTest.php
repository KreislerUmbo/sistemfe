<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaItemPasajeroController;
use App\Http\Controllers\AgenciaViajes\ReservaPasajeroController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaItemVueloPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use Illuminate\Http\Request;
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

    // Bug real (auditoría 2026-09-22): reserva_item_vuelo_pasajero (vuelo de
    // agencia, migración 2026_08_27_110000) es una FK sin cascadeOnDelete()
    // que quedó fuera del limpiado — borrar un ítem con vuelo ya cargado
    // tiraba un 500 (violación de FK de Postgres) en vez de completar el
    // borrado o devolver un 422 claro.
    public function test_quitar_item_con_vuelo_de_agencia_cargado_no_revienta(): void
    {
        [, $reservaItem1, , $pasajero1] = $this->crearReservaConDosPasajerosYDosItems();

        ReservaItemVueloPasajero::create([
            'reserva_item_id' => $reservaItem1->id,
            'reserva_pasajero_id' => $pasajero1->id,
            'vuelo_numero_ida' => 'LA2050',
            'vuelo_fecha_ida' => '2026-10-01',
        ]);

        $response = app(ReservaItemController::class)->destroy((string) $reservaItem1->id);

        $this->assertSame(200, $response->getStatusCode(), json_encode($response->getData(true)));
        $this->assertFalse(ReservaItem::where('id', $reservaItem1->id)->exists());
        $this->assertSame(0, ReservaItemVueloPasajero::where('reserva_item_id', $reservaItem1->id)->count());
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

    // Bug real (auditoría 2026-09-23): el guard chequeaba "¿esta reserva
    // tiene ALGUNA venta?" en vez de "¿ESTE pasajero está en alguna
    // venta?" — bloqueaba quitar a un pasajero NUNCA facturado solo porque
    // otro pasajero de la misma reserva ya lo estaba. Contradice el propio
    // diseño de "facturación múltiple por grupo de pasajeros" (una reserva
    // de 20, factura a 5, los otros 15 deben poder seguir editándose).
    public function test_quitar_pasajero_permitido_si_otro_pasajero_de_la_reserva_ya_facturo(): void
    {
        [$reserva, , , $pasajero1, $pasajero2] = $this->crearReservaConDosPasajerosYDosItems();

        ReservaVenta::create([
            'reserva_id' => $reserva->id, 'sale_id' => \App\Models\Sale\Sale::factory()->create()->id,
            'reserva_item_ids' => [], 'reserva_pasajero_ids' => [$pasajero1->id],
        ]);

        // pasajero2 nunca apareció en ningún ReservaVenta — debe poder
        // quitarse igual, aunque pasajero1 (otro, distinto) ya esté facturado.
        $response = app(ReservaPasajeroController::class)->destroy((string) $pasajero2->id);

        $this->assertSame(200, $response->getStatusCode(), json_encode($response->getData(true)));
        $this->assertFalse(ReservaPasajero::where('id', $pasajero2->id)->exists());
        // pasajero1 (el facturado) sigue intacto, sin tocar.
        $this->assertTrue(ReservaPasajero::where('id', $pasajero1->id)->exists());
    }

    // Mismo bug que arriba, ahora del lado de borrar el PASAJERO en vez del
    // ítem — reserva_item_vuelo_pasajero.reserva_pasajero_id también es FK
    // sin cascadeOnDelete().
    public function test_quitar_pasajero_con_vuelo_de_agencia_cargado_no_revienta(): void
    {
        [, $reservaItem1, , $pasajero1] = $this->crearReservaConDosPasajerosYDosItems();

        ReservaItemVueloPasajero::create([
            'reserva_item_id' => $reservaItem1->id,
            'reserva_pasajero_id' => $pasajero1->id,
            'vuelo_numero_ida' => 'LA2050',
            'vuelo_fecha_ida' => '2026-10-01',
        ]);

        $response = app(ReservaPasajeroController::class)->destroy((string) $pasajero1->id);

        $this->assertSame(200, $response->getStatusCode(), json_encode($response->getData(true)));
        $this->assertFalse(ReservaPasajero::where('id', $pasajero1->id)->exists());
        $this->assertSame(0, ReservaItemVueloPasajero::where('reserva_pasajero_id', $pasajero1->id)->count());
    }

    // ── ReservaPasajeroController::store() — agregar pasajero (2026-09-23) ──
    // Hallazgo de auditoría (baja confianza — nunca estuvo anotado como
    // alcance diferido a propósito, a diferencia de otros gaps del
    // módulo): no había ningún camino para sumar un pasajero de último
    // momento a una reserva ya aceptada.

    public function test_agregar_pasajero_exitoso_crea_shell_vacio(): void
    {
        [$reserva] = $this->crearReservaConDosPasajerosYDosItems();
        $totalAntes = $reserva->pasajeros()->count();

        $response = app(ReservaPasajeroController::class)
            ->store(new Request(['tipo_pax' => 'adulto']), (string) $reserva->id);
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode(), json_encode($body));
        $this->assertSame($totalAntes + 1, $reserva->pasajeros()->count());
        $this->assertSame('adulto', $body['reserva_pasajero']['tipo_pax']);
        $this->assertNull($body['reserva_pasajero']['nombre'], 'nace vacío, mismo criterio que al aceptar la alternativa');
    }

    public function test_agregar_pasajero_rechaza_si_reserva_no_activa(): void
    {
        [$reserva] = $this->crearReservaConDosPasajerosYDosItems();
        $reserva->update(['estado' => 'cancelada']);

        $response = app(ReservaPasajeroController::class)
            ->store(new Request(['tipo_pax' => 'adulto']), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_agregar_pasajero_con_nombre_y_documento_sincroniza_catalogo(): void
    {
        [$reserva] = $this->crearReservaConDosPasajerosYDosItems();

        $response = app(ReservaPasajeroController::class)->store(new Request([
            'tipo_pax' => 'adulto', 'nombre' => 'Pasajero Nuevo', 'documento' => '55443322',
        ]), (string) $reserva->id);
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode(), json_encode($body));
        $this->assertTrue(
            \App\Models\AgenciaViajes\PasajeroDocumento::where('numero_documento', '55443322')->exists(),
            'nombre+documento presentes desde el alta deben sincronizar el catálogo, mismo criterio que update()'
        );
    }

    public function test_agregar_pasajero_rechaza_tipo_pax_invalido(): void
    {
        [$reserva] = $this->crearReservaConDosPasajerosYDosItems();

        $response = app(ReservaPasajeroController::class)
            ->store(new Request(['tipo_pax' => 'no-existe']), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    // ── reserva-item-pasajeros (asignación, store()) ────────────────────
    // Bug real encontrado 2026-08-28: store() no tenía ninguno de los 2
    // guards que index()/destroy() de la misma clase ya tenían — se podía
    // asignar un pasajero nuevo a un ítem ya facturado, o en una reserva
    // no activa, desincronizando en silencio "quién estaba incluido" en
    // un comprobante SUNAT ya emitido.

    public function test_asignar_pasajero_exitoso_crea_asignacion(): void
    {
        [, $reservaItem1, , , $pasajero2] = $this->crearReservaConDosPasajerosYDosItems();

        $response = app(ReservaItemPasajeroController::class)
            ->store(new Request(['reserva_pasajero_id' => $pasajero2->id]), (string) $reservaItem1->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(
            ReservaItemPasajero::where('reserva_item_id', $reservaItem1->id)
                ->where('reserva_pasajero_id', $pasajero2->id)
                ->exists()
        );
    }

    public function test_asignar_pasajero_rechaza_si_reserva_no_activa(): void
    {
        [$reserva, $reservaItem1, , , $pasajero2] = $this->crearReservaConDosPasajerosYDosItems();
        $reserva->update(['estado' => 'cancelada']);

        $response = app(ReservaItemPasajeroController::class)
            ->store(new Request(['reserva_pasajero_id' => $pasajero2->id]), (string) $reservaItem1->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse(
            ReservaItemPasajero::where('reserva_item_id', $reservaItem1->id)
                ->where('reserva_pasajero_id', $pasajero2->id)
                ->exists()
        );
    }

    public function test_asignar_pasajero_rechaza_si_item_ya_facturado(): void
    {
        [$reserva, $reservaItem1, , , $pasajero2] = $this->crearReservaConDosPasajerosYDosItems();

        ReservaVenta::create([
            'reserva_id' => $reserva->id, 'sale_id' => \App\Models\Sale\Sale::factory()->create()->id,
            'reserva_item_ids' => [$reservaItem1->id], 'reserva_pasajero_ids' => [],
        ]);

        $response = app(ReservaItemPasajeroController::class)
            ->store(new Request(['reserva_pasajero_id' => $pasajero2->id]), (string) $reservaItem1->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('facturado', $response->getData(true)['message']);
        $this->assertFalse(
            ReservaItemPasajero::where('reserva_item_id', $reservaItem1->id)
                ->where('reserva_pasajero_id', $pasajero2->id)
                ->exists()
        );
    }
}
