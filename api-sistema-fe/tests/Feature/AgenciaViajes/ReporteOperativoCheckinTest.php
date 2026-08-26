<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReporteOperativoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemPasajeroController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 11d (pantalla del reporte operativo) — cubre ReservaItemPasajeroController::
// checkin(), acción nueva, y la exposición de checkin_realizado/checkin_hora/
// origen_tipo agregada a ReporteOperativoController::armarFila(). Mismo patrón de
// infraestructura que ReporteOperativoTest: Postgres real (sistemafe_test_migrations),
// transacción por test revertida.
class ReporteOperativoCheckinTest extends TestCase
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

    /**
     * Reserva activa con un ítem SIN vínculo específico (el caso mayoritario real,
     * confirmado en ReporteOperativoTest) y 2 pasajeros.
     */
    private function crearReservaConItemSinVinculo(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '77889900', 'full_name' => 'Cliente Test Checkin',
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

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $item = ReservaItem::where('alternativa_item_id', $itemManual->id)->firstOrFail();
        [$pasajero1, $pasajero2] = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get()->all();

        return compact('reserva', 'item', 'pasajero1', 'pasajero2');
    }

    public function test_checkin_crea_el_vinculo_cuando_no_existia(): void
    {
        $f = $this->crearReservaConItemSinVinculo();

        $this->assertDatabaseCount('reserva_item_pasajero', 0);

        $response = app(ReservaItemPasajeroController::class)->checkin(
            new Request(['checkin_realizado' => true]),
            (string) $f['item']->id,
            (string) $f['pasajero1']->id
        );
        $body = $response->getData(true);

        $this->assertSame(200, $body['code']);
        $asignacion = ReservaItemPasajero::where('reserva_item_id', $f['item']->id)
            ->where('reserva_pasajero_id', $f['pasajero1']->id)
            ->firstOrFail();
        $this->assertTrue($asignacion->checkin_realizado);
        $this->assertNotNull($asignacion->checkin_hora);

        // El ítem no tenía NINGÚN vínculo específico (aplica a todos los pasajeros de
        // la reserva) — checkin() materializa el vínculo de AMBOS pasajeros para no
        // "promover" el ítem a vínculo específico y excluir al resto del reporte en
        // silencio. El otro pasajero queda vinculado pero SIN check-in marcado.
        $this->assertDatabaseCount('reserva_item_pasajero', 2);
        $asignacionOtro = ReservaItemPasajero::where('reserva_item_id', $f['item']->id)
            ->where('reserva_pasajero_id', $f['pasajero2']->id)
            ->firstOrFail();
        $this->assertFalse($asignacionOtro->checkin_realizado);
    }

    public function test_checkin_sobre_vinculo_existente_actualiza_sin_duplicar(): void
    {
        $f = $this->crearReservaConItemSinVinculo();

        app(ReservaItemPasajeroController::class)->store(
            new Request(['reserva_pasajero_id' => $f['pasajero1']->id]),
            (string) $f['item']->id
        );
        $this->assertDatabaseCount('reserva_item_pasajero', 1);

        app(ReservaItemPasajeroController::class)->checkin(
            new Request(['checkin_realizado' => true]),
            (string) $f['item']->id,
            (string) $f['pasajero1']->id
        );

        $this->assertDatabaseCount('reserva_item_pasajero', 1);
        $asignacion = ReservaItemPasajero::where('reserva_item_id', $f['item']->id)
            ->where('reserva_pasajero_id', $f['pasajero1']->id)
            ->firstOrFail();
        $this->assertTrue($asignacion->checkin_realizado);
    }

    public function test_desmarcar_checkin_limpia_checkin_hora(): void
    {
        $f = $this->crearReservaConItemSinVinculo();

        app(ReservaItemPasajeroController::class)->checkin(
            new Request(['checkin_realizado' => true]),
            (string) $f['item']->id,
            (string) $f['pasajero1']->id
        );
        app(ReservaItemPasajeroController::class)->checkin(
            new Request(['checkin_realizado' => false]),
            (string) $f['item']->id,
            (string) $f['pasajero1']->id
        );

        $asignacion = ReservaItemPasajero::where('reserva_item_id', $f['item']->id)
            ->where('reserva_pasajero_id', $f['pasajero1']->id)
            ->firstOrFail();
        $this->assertFalse($asignacion->checkin_realizado);
        $this->assertNull($asignacion->checkin_hora);
    }

    public function test_checkin_de_pasajero_de_otra_reserva_devuelve_422(): void
    {
        $f = $this->crearReservaConItemSinVinculo();
        $otro = $this->crearReservaConItemSinVinculo();

        $response = app(ReservaItemPasajeroController::class)->checkin(
            new Request(['checkin_realizado' => true]),
            (string) $f['item']->id,
            (string) $otro['pasajero1']->id
        );

        $this->assertSame(422, $response->getData(true)['code']);
        $this->assertDatabaseCount('reserva_item_pasajero', 0);
    }

    public function test_checkin_en_reserva_no_activa_devuelve_422(): void
    {
        $f = $this->crearReservaConItemSinVinculo();
        $f['reserva']->update(['estado' => 'cancelada']);

        $response = app(ReservaItemPasajeroController::class)->checkin(
            new Request(['checkin_realizado' => true]),
            (string) $f['item']->id,
            (string) $f['pasajero1']->id
        );

        $this->assertSame(422, $response->getData(true)['code']);
    }

    public function test_reporte_operativo_expone_checkin_y_origen_tipo(): void
    {
        $f = $this->crearReservaConItemSinVinculo();

        app(ReservaItemPasajeroController::class)->checkin(
            new Request(['checkin_realizado' => true]),
            (string) $f['item']->id,
            (string) $f['pasajero1']->id
        );

        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-10', 'fecha_hasta' => '2026-09-10',
        ]))->getData(true);

        $filas = collect($body['filas']);
        $filaPasajero1 = $filas->first(fn ($fila) => $fila['pasajero']['id'] === $f['pasajero1']->id);
        $filaPasajero2 = $filas->first(fn ($fila) => $fila['pasajero']['id'] === $f['pasajero2']->id);

        $this->assertSame('manual', $filaPasajero1['origen_tipo']);
        $this->assertTrue($filaPasajero1['checkin_realizado']);
        $this->assertNotNull($filaPasajero1['checkin_hora']);

        // pasajero2 sigue apareciendo en el reporte (checkin() materializó su vínculo
        // sin marcarlo) — si esto fallara, el bug real sería que marcar check-in de
        // un pasajero hace desaparecer al resto del reporte la próxima carga.
        $this->assertNotNull($filaPasajero2, 'pasajero2 debe seguir apareciendo en el reporte tras el check-in de pasajero1');
        $this->assertFalse($filaPasajero2['checkin_realizado']);
        $this->assertNull($filaPasajero2['checkin_hora']);
        $this->assertTrue($filaPasajero1['vinculo_especifico']);
        $this->assertTrue($filaPasajero2['vinculo_especifico']);
    }
}
