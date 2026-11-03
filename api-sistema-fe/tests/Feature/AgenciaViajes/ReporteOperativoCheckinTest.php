<?php

namespace Tests\Feature\AgenciaViajes;

use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use App\Http\Controllers\AgenciaViajes\ReporteOperativoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaItemPasajeroController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemCheckin;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Services\AgenciaViajes\QuitarPasajeroReservaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Check-in del reporte operativo: ReservaItemPasajeroController::checkin() y lo que
// expone ReporteOperativoController::armarFila(). Desde T-041 (H30) el check-in vive
// en reserva_item_checkins y nunca cambia la asignación pasajero↔ítem
// (reserva_item_pasajero), que es la que usa la facturación por grupos. Mismo patrón
// de infraestructura que ReporteOperativoTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class ReporteOperativoCheckinTest extends TestCase
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

        $this->verTodasLasReservas();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * Reserva activa con 2 pasajeros y un ítem SIN asignación ("para todos", el caso
     * mayoritario real, confirmado en ReporteOperativoTest). Con $conSegundoItem, un
     * segundo ítem para poder quitar el primero (no se quita el último).
     */
    private function crearReservaConItemSinVinculo(bool $conSegundoItem = false): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999), 'full_name' => 'Cliente Test Checkin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-04' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-10', 'fecha_viaje_hasta' => '2026-09-10',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa checkin',
            'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);

        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 28]);

        $itemManual = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Tour city checkin',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 20, 'precio_venta_snapshot' => 30, 'precio_convertido' => 30,
        ]);
        if ($conSegundoItem) {
            AlternativaItem::create([
                'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
                'descripcion_manual' => 'Traslado checkin',
                'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
                'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
            ]);
        }

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $item = ReservaItem::where('alternativa_item_id', $itemManual->id)->firstOrFail();
        [$pasajero1, $pasajero2] = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get()->all();

        return compact('reserva', 'item', 'pasajero1', 'pasajero2');
    }

    private function marcar(ReservaItem $item, ReservaPasajero $pasajero, bool $realizado): array
    {
        return app(ReservaItemPasajeroController::class)->checkin(
            new Request(['checkin_realizado' => $realizado]),
            (string) $item->id,
            (string) $pasajero->id
        )->getData(true);
    }

    private function checkinsDe(ReservaItem $item)
    {
        return ReservaItemCheckin::where('reserva_item_id', $item->id)->pluck('reserva_pasajero_id')->all();
    }

    private function filasDelReporte(ReservaItem $item)
    {
        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-10', 'fecha_hasta' => '2026-09-10',
        ]))->getData(true);

        return collect($body['filas'])->where('reserva_item_id', $item->id)->keyBy(fn ($fila) => $fila['pasajero']['id']);
    }

    // ── CA1 ─────────────────────────────────────────────────────────────────

    public function test_ca1_checkin_en_servicio_para_todos_no_crea_asignacion(): void
    {
        $f = $this->crearReservaConItemSinVinculo();

        $body = $this->marcar($f['item'], $f['pasajero1'], true);

        $this->assertSame(200, $body['code']);
        $this->assertSame([$f['pasajero1']->id], $this->checkinsDe($f['item']));
        $this->assertNotNull($body['reserva_item_checkin']['checkin_hora']);
        $this->assertSame(0, ReservaItemPasajero::where('reserva_item_id', $f['item']->id)->count(), 'el servicio sigue "para todos"');
    }

    public function test_ca1_tras_el_checkin_facturacion_por_grupos_lo_sigue_ofreciendo_a_mano(): void
    {
        $tenant = new \App\Models\Tenant();
        $tenant->id = 'test-tenant-'.uniqid();
        $tenant->facturacion_habilitada = true;
        app(\Stancl\Tenancy\Tenancy::class)->tenant = $tenant;

        $f = $this->crearReservaConItemSinVinculo();
        $this->marcar($f['item'], $f['pasajero1'], true);

        // Factura solo para el pasajero 1 (grupos): el servicio "para todos" se ofrece
        // para elegir a mano, no queda "pendiente: faltan pasajeros" (H30).
        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$f['pasajero1']->id],
        ]), (string) $f['reserva']->id);

        $this->assertSame(200, $preview->getStatusCode(), json_encode($preview->getData(true)));
        $cuerpo = $preview->getData(true);
        $this->assertContains($f['item']->id, collect($cuerpo['items_sin_asignar_disponibles'])->pluck('reserva_item_id')->all());
        $this->assertNotContains($f['item']->id, collect($cuerpo['items_pendientes_por_pasajero_faltante'])->pluck('reserva_item_id')->all());
    }

    // ── CA2 ─────────────────────────────────────────────────────────────────

    public function test_ca2_desmarcar_borra_el_checkin_y_no_toca_la_asignacion(): void
    {
        $f = $this->crearReservaConItemSinVinculo();
        app(ReservaItemPasajeroController::class)->store(
            new Request(['reserva_pasajero_id' => $f['pasajero1']->id]),
            (string) $f['item']->id
        );

        $this->marcar($f['item'], $f['pasajero1'], true);
        $this->assertSame([$f['pasajero1']->id], $this->checkinsDe($f['item']));

        $body = $this->marcar($f['item'], $f['pasajero1'], false);

        $this->assertSame(200, $body['code']);
        $this->assertSame([], $this->checkinsDe($f['item']));
        $this->assertSame(
            [$f['pasajero1']->id],
            ReservaItemPasajero::where('reserva_item_id', $f['item']->id)->pluck('reserva_pasajero_id')->all(),
            'la asignación sigue igual'
        );
    }

    public function test_ca2_desmarcar_en_servicio_para_todos_lo_deja_para_todos(): void
    {
        $f = $this->crearReservaConItemSinVinculo();

        $this->marcar($f['item'], $f['pasajero1'], true);
        $this->marcar($f['item'], $f['pasajero1'], false);

        $this->assertSame([], $this->checkinsDe($f['item']));
        $this->assertSame(0, ReservaItemPasajero::where('reserva_item_id', $f['item']->id)->count());
    }

    // ── CA3 ─────────────────────────────────────────────────────────────────

    public function test_ca3_checkin_de_pasajero_no_asignado_al_servicio_devuelve_422(): void
    {
        $f = $this->crearReservaConItemSinVinculo();
        app(ReservaItemPasajeroController::class)->store(
            new Request(['reserva_pasajero_id' => $f['pasajero1']->id]),
            (string) $f['item']->id
        );

        $body = $this->marcar($f['item'], $f['pasajero2'], true);

        $this->assertSame(422, $body['code']);
        $this->assertSame([], $this->checkinsDe($f['item']));
        $this->assertSame(1, ReservaItemPasajero::where('reserva_item_id', $f['item']->id)->count());
    }

    public function test_marcar_dos_veces_no_duplica_ni_cambia_la_hora(): void
    {
        $f = $this->crearReservaConItemSinVinculo();

        $primera = $this->marcar($f['item'], $f['pasajero1'], true);
        $segunda = $this->marcar($f['item'], $f['pasajero1'], true);

        $this->assertSame(200, $segunda['code']);
        $this->assertSame(1, ReservaItemCheckin::where('reserva_item_id', $f['item']->id)->count());
        $this->assertSame($primera['reserva_item_checkin']['id'], $segunda['reserva_item_checkin']['id']);
    }

    public function test_checkin_de_pasajero_de_otra_reserva_devuelve_422(): void
    {
        $f = $this->crearReservaConItemSinVinculo();
        $otro = $this->crearReservaConItemSinVinculo();

        $body = $this->marcar($f['item'], $otro['pasajero1'], true);

        $this->assertSame(422, $body['code']);
        $this->assertSame([], $this->checkinsDe($f['item']));
    }

    public function test_checkin_en_reserva_no_activa_devuelve_422(): void
    {
        $f = $this->crearReservaConItemSinVinculo();
        $f['reserva']->update(['estado' => 'cancelada']);

        $this->assertSame(422, $this->marcar($f['item'], $f['pasajero1'], true)['code']);
    }

    public function test_reporte_operativo_expone_checkin_y_origen_tipo(): void
    {
        $f = $this->crearReservaConItemSinVinculo();

        $this->marcar($f['item'], $f['pasajero1'], true);

        $filas = $this->filasDelReporte($f['item']);
        $filaPasajero1 = $filas->get($f['pasajero1']->id);
        $filaPasajero2 = $filas->get($f['pasajero2']->id);

        $this->assertSame('manual', $filaPasajero1['origen_tipo']);
        $this->assertTrue($filaPasajero1['checkin_realizado']);
        $this->assertNotNull($filaPasajero1['checkin_hora']);

        // Los dos siguen en el reporte y el servicio sigue "para todos".
        $this->assertNotNull($filaPasajero2, 'pasajero2 debe seguir apareciendo en el reporte tras el check-in de pasajero1');
        $this->assertFalse($filaPasajero2['checkin_realizado']);
        $this->assertNull($filaPasajero2['checkin_hora']);
        $this->assertFalse($filaPasajero1['vinculo_especifico']);
        $this->assertFalse($filaPasajero2['vinculo_especifico']);
    }

    // ── CA4 ─────────────────────────────────────────────────────────────────

    public function test_ca4_la_migracion_copia_los_checkins_viejos_y_no_toca_asignaciones(): void
    {
        $f = $this->crearReservaConItemSinVinculo();
        // Como lo dejaba el check-in viejo: asignación de todos y el check-in en la fila.
        $hora = '2026-09-10 13:45:00';
        DB::table('reserva_item_pasajero')->insert([
            ['reserva_item_id' => $f['item']->id, 'reserva_pasajero_id' => $f['pasajero1']->id,
                'checkin_realizado' => true, 'checkin_hora' => $hora, 'created_at' => now(), 'updated_at' => now()],
            ['reserva_item_id' => $f['item']->id, 'reserva_pasajero_id' => $f['pasajero2']->id,
                'checkin_realizado' => false, 'checkin_hora' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migracion = require database_path('migrations/tenant/verticals/agencia-viajes/2026_11_03_200100_copiar_checkins_a_reserva_item_checkins.php');
        $migracion->up();
        $migracion->up(); // idempotente

        $this->assertSame([$f['pasajero1']->id], $this->checkinsDe($f['item']));
        $this->assertSame(2, ReservaItemPasajero::where('reserva_item_id', $f['item']->id)->count(), 'las asignaciones quedan como estaban (opción a)');

        $filas = $this->filasDelReporte($f['item']);
        $this->assertTrue($filas->get($f['pasajero1']->id)['checkin_realizado']);
        $this->assertSame($hora, $filas->get($f['pasajero1']->id)['checkin_hora']);
        $this->assertFalse($filas->get($f['pasajero2']->id)['checkin_realizado']);
    }

    // ── CA6 ─────────────────────────────────────────────────────────────────

    public function test_ca6_quitar_un_servicio_con_checkin_no_falla_y_limpia_el_checkin(): void
    {
        $f = $this->crearReservaConItemSinVinculo(conSegundoItem: true);
        $this->marcar($f['item'], $f['pasajero1'], true);

        $response = app(ReservaItemController::class)->destroy((string) $f['item']->id);

        $this->assertSame(200, $response->getStatusCode(), json_encode($response->getData(true)));
        $this->assertNull(ReservaItem::find($f['item']->id));
        $this->assertSame([], $this->checkinsDe($f['item']));
    }

    public function test_quitar_un_pasajero_con_checkin_no_falla_y_limpia_su_checkin(): void
    {
        $f = $this->crearReservaConItemSinVinculo();
        $this->marcar($f['item'], $f['pasajero1'], true);
        $this->marcar($f['item'], $f['pasajero2'], true);

        // Quitar un pasajero va por "Modificar reserva", que usa este servicio.
        app(QuitarPasajeroReservaService::class)->quitar($f['pasajero1']->fresh());

        $this->assertNull(ReservaPasajero::find($f['pasajero1']->id));
        $this->assertSame([$f['pasajero2']->id], $this->checkinsDe($f['item']));
    }
}
