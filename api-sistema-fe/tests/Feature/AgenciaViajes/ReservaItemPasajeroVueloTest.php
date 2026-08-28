<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReporteOperativoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemPasajeroController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaItemVueloPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Auditoría de UX/funcionalidad del módulo de Reservas (2026-08-27) — vuelo
// vendido por la AGENCIA (distinto de reserva_pasajeros.vuelo_*, el vuelo que
// el pasajero compra por su cuenta). Ver migración
// 2026_08_27_110000_create_reserva_item_vuelo_pasajero_table y
// ReservaItemPasajeroController::actualizarVuelo().
//
// Corregido el mismo día tras un bug real en pruebas en vivo: la primera
// versión guardaba el vuelo como columnas de reserva_item_pasajero — la
// MISMA fila que edita el checkbox del tab "Asignación pasajero↔ítem"
// (store()/destroy() de este mismo controller), que sirve para agrupar
// facturación/reporte, sin ninguna relación con el vuelo. Desmarcar un
// pasajero ahí borraba el vuelo ya cargado. Ahora vive en
// ReservaItemVueloPasajero, tabla propia — test_vuelo_no_se_pierde_al_
// destildar_checkbox_de_asignacion() reproduce exactamente ese bug y
// confirma que ya no pasa.
//
// Mismo patrón de infraestructura que el resto del módulo: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class ReservaItemPasajeroVueloTest extends TestCase
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

    /**
     * Reserva activa, fecha_viaje_desde 2026-09-01, 2 pasajeros, con UN ítem
     * origen_tipo='pasaje_aereo' (día 1) más un ítem manual (día 1, sin
     * relación con vuelos — sirve para confirmar que vuelo_agencia_* nunca se
     * mezcla con una fila que no es el pasaje aéreo).
     */
    private function crearReservaConPasajeAereo(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '77889900', 'full_name' => 'Cliente Test Vuelo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0600-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Cusco', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 28]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        $itemVuelo = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'pasaje_aereo', 'dia_referencial' => 1,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 200, 'precio_venta_snapshot' => 250, 'precio_convertido' => 250,
        ]);
        DB::table('cotizacion_pasaje_aereo')->insert([
            'alternativa_item_id' => $itemVuelo->id, 'aerolinea' => 'LATAM (cotizado)',
            'moneda' => 'PEN', 'tarifa_base_adulto' => 200,
            'fecha_cotizado' => now(), 'costo_total' => 200, 'precio_venta_total' => 250,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemManual = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Traslado suelto', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $riVuelo = ReservaItem::where('alternativa_item_id', $itemVuelo->id)->firstOrFail();
        $riManual = ReservaItem::where('alternativa_item_id', $itemManual->id)->firstOrFail();
        [$pasajero1, $pasajero2] = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get()->all();

        return compact('reserva', 'riVuelo', 'riManual', 'pasajero1', 'pasajero2');
    }

    public function test_actualizar_vuelo_exitoso_crea_fila_propia_sin_tocar_a_otros_pasajeros(): void
    {
        $f = $this->crearReservaConPasajeAereo();

        $response = app(ReservaItemPasajeroController::class)->actualizarVuelo(
            new Request([
                'vuelo_numero_ida' => 'LA2050', 'vuelo_fecha_ida' => '2026-09-01', 'vuelo_hora_ida' => '07:30',
                'vuelo_aerolinea_confirmada' => 'LATAM',
            ]),
            (string) $f['riVuelo']->id,
            (string) $f['pasajero1']->id
        );

        $this->assertSame(200, $response->getStatusCode());

        // Solo se creó la fila del pasajero editado — a diferencia del diseño
        // anterior, acá NO se materializa a nadie más.
        $this->assertSame(1, ReservaItemVueloPasajero::where('reserva_item_id', $f['riVuelo']->id)->count());
        // Y tampoco toca reserva_item_pasajero (la tabla del checkbox de
        // Asignación) — quedan completamente desacopladas.
        $this->assertSame(0, ReservaItemPasajero::where('reserva_item_id', $f['riVuelo']->id)->count());

        $vuelo = ReservaItemVueloPasajero::where('reserva_item_id', $f['riVuelo']->id)
            ->where('reserva_pasajero_id', $f['pasajero1']->id)->first();
        $this->assertSame('LA2050', $vuelo->vuelo_numero_ida);
        $this->assertSame('LATAM', $vuelo->vuelo_aerolinea_confirmada);
    }

    public function test_actualizar_vuelo_segunda_vez_actualiza_la_misma_fila(): void
    {
        $f = $this->crearReservaConPasajeAereo();

        app(ReservaItemPasajeroController::class)->actualizarVuelo(
            new Request(['vuelo_numero_ida' => 'LA2050']),
            (string) $f['riVuelo']->id,
            (string) $f['pasajero1']->id
        );
        app(ReservaItemPasajeroController::class)->actualizarVuelo(
            new Request(['vuelo_numero_ida' => 'LA9999']),
            (string) $f['riVuelo']->id,
            (string) $f['pasajero1']->id
        );

        $this->assertSame(1, ReservaItemVueloPasajero::where('reserva_item_id', $f['riVuelo']->id)->count());
        $vuelo = ReservaItemVueloPasajero::where('reserva_item_id', $f['riVuelo']->id)->first();
        $this->assertSame('LA9999', $vuelo->vuelo_numero_ida);
    }

    public function test_actualizar_vuelo_rechaza_si_item_no_es_pasaje_aereo(): void
    {
        $f = $this->crearReservaConPasajeAereo();

        $response = app(ReservaItemPasajeroController::class)->actualizarVuelo(
            new Request(['vuelo_numero_ida' => 'LA2050']),
            (string) $f['riManual']->id,
            (string) $f['pasajero1']->id
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, ReservaItemVueloPasajero::where('reserva_item_id', $f['riManual']->id)->count());
    }

    public function test_actualizar_vuelo_rechaza_si_reserva_no_activa(): void
    {
        $f = $this->crearReservaConPasajeAereo();
        $f['reserva']->update(['estado' => 'cancelada']);

        $response = app(ReservaItemPasajeroController::class)->actualizarVuelo(
            new Request(['vuelo_numero_ida' => 'LA2050']),
            (string) $f['riVuelo']->id,
            (string) $f['pasajero1']->id
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    // Reproduce el bug real encontrado en pruebas en vivo (2026-08-27) y
    // confirma el fix: marcar/desmarcar el checkbox del tab Asignación
    // (store()/destroy() de reserva_item_pasajero) NO debe tocar el vuelo de
    // agencia ya guardado, porque ahora vive en una tabla completamente
    // aparte.
    public function test_vuelo_no_se_pierde_al_destildar_checkbox_de_asignacion(): void
    {
        $f = $this->crearReservaConPasajeAereo();

        app(ReservaItemPasajeroController::class)->actualizarVuelo(
            new Request(['vuelo_numero_ida' => 'LA2050']),
            (string) $f['riVuelo']->id,
            (string) $f['pasajero1']->id
        );

        // Simula "marcar" el checkbox de Asignación para este mismo
        // (ítem, pasajero) — store() del controller.
        app(ReservaItemPasajeroController::class)->store(
            new Request(['reserva_pasajero_id' => $f['pasajero1']->id]),
            (string) $f['riVuelo']->id
        );
        $asignacion = ReservaItemPasajero::where('reserva_item_id', $f['riVuelo']->id)
            ->where('reserva_pasajero_id', $f['pasajero1']->id)->firstOrFail();

        // Simula "desmarcar" — destroy() del controller, el mismo camino que
        // dispara toggleAsignacion() en el frontend.
        app(ReservaItemPasajeroController::class)->destroy((string) $asignacion->id);

        $this->assertSame(0, ReservaItemPasajero::where('reserva_item_id', $f['riVuelo']->id)->count(), 'el checkbox quedó desmarcado');

        // El vuelo sigue intacto — antes de la corrección, esto quedaba en 0.
        $vuelo = ReservaItemVueloPasajero::where('reserva_item_id', $f['riVuelo']->id)
            ->where('reserva_pasajero_id', $f['pasajero1']->id)->first();
        $this->assertNotNull($vuelo, 'el vuelo de agencia no debe borrarse al desmarcar el checkbox de Asignación');
        $this->assertSame('LA2050', $vuelo->vuelo_numero_ida);
    }

    // Confirma que el vuelo por CUENTA PROPIA del pasajero (reserva_pasajeros.
    // vuelo_*) no aparece mezclado con el vuelo vendido por la AGENCIA — cada
    // uno en su propio campo, y vuelo_agencia_* nunca se pega a una fila que
    // no es el ítem de vuelo.
    public function test_reporte_operativo_no_mezcla_vuelo_propio_con_vuelo_agencia(): void
    {
        $f = $this->crearReservaConPasajeAereo();

        $f['pasajero1']->update([
            'vuelo_aerolinea_ida' => 'Sky (propio)', 'vuelo_fecha_ida' => '2026-09-01', 'vuelo_hora_ida' => '05:00',
        ]);

        app(ReservaItemPasajeroController::class)->actualizarVuelo(
            new Request([
                'vuelo_numero_ida' => 'LA2050', 'vuelo_fecha_ida' => '2026-09-01', 'vuelo_hora_ida' => '07:30',
            ]),
            (string) $f['riVuelo']->id,
            (string) $f['pasajero1']->id
        );

        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-01',
        ]))->getData(true);

        $filaVuelo = collect($body['filas'])
            ->first(fn ($fila) => $fila['reserva_item_id'] === $f['riVuelo']->id && $fila['pasajero']['id'] === $f['pasajero1']->id);
        $this->assertNotNull($filaVuelo);
        $this->assertSame('LA2050', $filaVuelo['vuelo_agencia_ida']['numero']);
        $this->assertSame('2026-09-01', $filaVuelo['vuelo_agencia_ida']['fecha']);
        $this->assertSame('Sky (propio)', $filaVuelo['vuelo_ida']['aerolinea'], 'el vuelo propio sigue intacto, sin pisarse con el de agencia');

        // La fila del pasajero2 en el mismo ítem no tiene vuelo de agencia
        // cargado (nunca se editó) — no hereda el de pasajero1.
        $filaVueloPasajero2 = collect($body['filas'])
            ->first(fn ($fila) => $fila['reserva_item_id'] === $f['riVuelo']->id && $fila['pasajero']['id'] === $f['pasajero2']->id);
        $this->assertNotNull($filaVueloPasajero2);
        $this->assertNull($filaVueloPasajero2['vuelo_agencia_ida']);

        // La fila del ítem manual (no es un vuelo) nunca debe traer
        // vuelo_agencia_ida, aunque sea del mismo pasajero.
        $filaManual = collect($body['filas'])
            ->first(fn ($fila) => $fila['reserva_item_id'] === $f['riManual']->id && $fila['pasajero']['id'] === $f['pasajero1']->id);
        $this->assertNotNull($filaManual);
        $this->assertNull($filaManual['vuelo_agencia_ida'], 'vuelo_agencia_ida no debe aparecer en una fila que no es el ítem de vuelo');
        $this->assertSame('Sky (propio)', $filaManual['vuelo_ida']['aerolinea'], 'vuelo propio sí se pega a cualquier fila del pasajero, ese comportamiento no cambió');
    }
}
