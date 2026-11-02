<?php

namespace Tests\Feature\AgenciaViajes;

use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Auditoría del módulo Reservas/Cotizador (2026-08-27) — cierra dos gaps
// reales encontrados a partir de una pregunta del usuario sobre la
// cotización CDKM-0826-0000002 (el cálculo estaba bien, tenía 2 niños
// reales, pero no había forma de excluir a uno ni de editar el pasaje
// aéreo después de creado):
// 1. pax_incluidos ahora es seleccionable (antes PasajeAereoForm.vue nunca
//    lo mandaba, siempre quedaba en null = "todos los pasajeros").
// 2. actualizarPasajeAereo() — edición estructural completa, antes solo
//    existía el alta.
// Mismo patrón de infraestructura que el resto del módulo: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class AlternativaItemPasajeAereoTest extends TestCase
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

        // T-025: el cotizador pregunta a CotizacionPolicy; estos tests prueban otras reglas.
        $this->verTodasLasReservas();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /** Alternativa con 2 adultos, 2 niños y 1 infante — mismo shape que CDKM-0826-0000002 + un infante. */
    private function crearAlternativaConFamilia(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667788', 'full_name' => 'Cliente Test Pasaje Aereo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0700-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adulto1 = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 36]);
        $adulto2 = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 37]);
        $nino1 = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'nino', 'edad' => 10]);
        $nino2 = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'nino', 'edad' => 4]);
        $infante = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'infante', 'edad' => 1]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        return compact('alternativa', 'adulto1', 'adulto2', 'nino1', 'nino2', 'infante');
    }

    private function payloadBase(): array
    {
        return [
            'origen_tipo' => 'pasaje_aereo',
            'aerolinea' => 'LATAM',
            'itinerario' => 'LIMA - TARAPOTO',
            'moneda' => 'PEN',
            'tarifa_base_adulto' => 500,
            'tarifa_base_nino' => 450,
            'tarifa_base_infante' => 200,
            'fee_agencia_monto' => 300,
            'dia_referencial' => 1,
        ];
    }

    public function test_crear_sin_pax_incluidos_cuenta_todos_los_pasajeros(): void
    {
        $f = $this->crearAlternativaConFamilia();

        $response = app(AlternativaItemController::class)->store(
            new Request($this->payloadBase()),
            (string) $f['alternativa']->id
        );
        $body = $response->getData(true);

        // 2 adultos*500 + 2 niños*450 + 1 infante*200 = 2100; fee 300 × 5 pasajeros (T-076) = 1500 → venta 3600.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertEquals(2100.0, (float) $body['alternativa_item']['costo_snapshot']);
        $this->assertEquals(3600.0, (float) $body['alternativa_item']['precio_venta_snapshot']);
        // T-007: un boleto es por persona — guarda la lista, no null ("todos los que haya después").
        $this->assertSame(
            [$f['adulto1']->id, $f['adulto2']->id, $f['nino1']->id, $f['nino2']->id, $f['infante']->id],
            $body['alternativa_item']['pax_incluidos']
        );

        // Un pasajero agregado después no queda dentro de ese boleto.
        CotizacionPasajero::create(['cotizacion_id' => $f['alternativa']->cotizacion_id, 'tipo_pax' => 'adulto', 'edad' => 40]);
        $this->assertCount(5, AlternativaItem::findOrFail($body['alternativa_item']['id'])->pax_incluidos);
    }

    // Reproduce el caso real que motivó esta sesión: excluir a UNO de los 2
    // niños (pax_incluidos parcial) — el costo debe bajar a solo 1 tarifa de
    // niño, no 2.
    public function test_crear_con_pax_incluidos_excluye_al_pasajero_no_marcado(): void
    {
        $f = $this->crearAlternativaConFamilia();

        $payload = $this->payloadBase();
        $payload['pax_incluidos'] = [$f['adulto1']->id, $f['adulto2']->id, $f['nino1']->id]; // nino2 queda afuera

        $response = app(AlternativaItemController::class)->store(new Request($payload), (string) $f['alternativa']->id);
        $body = $response->getData(true);

        // 1450 de tarifas; fee 300 × 3 pasajeros = 900 → venta 2350.
        $this->assertEquals(1450.0, (float) $body['alternativa_item']['costo_snapshot']);
        $this->assertEquals(2350.0, (float) $body['alternativa_item']['precio_venta_snapshot']);
        $this->assertSame([$f['adulto1']->id, $f['adulto2']->id, $f['nino1']->id], $body['alternativa_item']['pax_incluidos']);
    }

    public function test_crear_con_infante_incluido_suma_su_tarifa(): void
    {
        $f = $this->crearAlternativaConFamilia();

        $payload = $this->payloadBase();
        $payload['pax_incluidos'] = [$f['adulto1']->id, $f['infante']->id];

        $response = app(AlternativaItemController::class)->store(new Request($payload), (string) $f['alternativa']->id);
        $body = $response->getData(true);

        // 700 de tarifas; fee 300 × 2 pasajeros (el infante paga fee) = 600 → venta 1300.
        $this->assertEquals(700.0, (float) $body['alternativa_item']['costo_snapshot']);
        $this->assertEquals(1300.0, (float) $body['alternativa_item']['precio_venta_snapshot']);
    }

    public function test_actualizar_pasaje_aereo_recalcula_y_persiste_en_ambas_tablas(): void
    {
        $f = $this->crearAlternativaConFamilia();

        $creado = app(AlternativaItemController::class)->store(new Request($this->payloadBase()), (string) $f['alternativa']->id)
            ->getData(true)['alternativa_item'];

        $payloadEditado = $this->payloadBase();
        $payloadEditado['tarifa_base_nino'] = 470; // corrige la tarifa
        $payloadEditado['pax_incluidos'] = [$f['adulto1']->id, $f['adulto2']->id, $f['nino1']->id]; // ahora excluye a nino2

        $response = app(AlternativaItemController::class)->actualizarPasajeAereo(new Request($payloadEditado), (string) $creado['id']);
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        // 1470 de tarifas; fee 300 × 3 pasajeros = 900 → venta 2370.
        $this->assertEquals(1470.0, (float) $body['alternativa_item']['costo_snapshot']);
        $this->assertEquals(2370.0, (float) $body['alternativa_item']['precio_venta_snapshot']);

        $cotizacionPasajeAereo = DB::table('cotizacion_pasaje_aereo')->where('alternativa_item_id', $creado['id'])->first();
        $this->assertEquals(470.0, (float) $cotizacionPasajeAereo->tarifa_base_nino);
        $this->assertEquals(1470.0, (float) $cotizacionPasajeAereo->costo_total);
    }

    public function test_actualizar_pasaje_aereo_rechaza_si_origen_tipo_no_es_pasaje_aereo(): void
    {
        $f = $this->crearAlternativaConFamilia();

        $itemManual = AlternativaItem::create([
            'alternativa_id' => $f['alternativa']->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Traslado', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        $response = app(AlternativaItemController::class)->actualizarPasajeAereo(new Request($this->payloadBase()), (string) $itemManual->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_actualizar_pasaje_aereo_rechaza_si_ya_tiene_reserva_generada(): void
    {
        $f = $this->crearAlternativaConFamilia();

        $creado = app(AlternativaItemController::class)->store(new Request($this->payloadBase()), (string) $f['alternativa']->id)
            ->getData(true)['alternativa_item'];

        ReservaItem::create([
            'reserva_id' => DB::table('reserva')->insertGetId([
                'alternativa_id' => $f['alternativa']->id, 'estado' => 'activa',
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'alternativa_item_id' => $creado['id'],
            'fecha_origen' => 'auto',
        ]);

        $response = app(AlternativaItemController::class)->actualizarPasajeAereo(new Request($this->payloadBase()), (string) $creado['id']);

        $this->assertSame(422, $response->getStatusCode());
    }
    public function test_editar_sin_pax_incluidos_cubre_a_todos_nunca_queda_vacio(): void
    {
        $f = $this->crearAlternativaConFamilia();
        $payload = $this->payloadBase();
        $payload['pax_incluidos'] = [$f['adulto1']->id];
        $creado = app(AlternativaItemController::class)->store(new Request($payload), (string) $f['alternativa']->id)
            ->getData(true)['alternativa_item'];

        $payload['pax_incluidos'] = [];
        $response = app(AlternativaItemController::class)->actualizarPasajeAereo(new Request($payload), (string) $creado['id']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(5, AlternativaItem::findOrFail($creado['id'])->pax_incluidos);
    }

    // ── T-075: un pasaje aéreo nunca se queda sin pasajeros ──────────────────

    /** Lista para actualizarPasajeros(): todos los pasajeros de la cotización salvo $quitar. */
    private function pasajerosSin(array $f, array $quitar): array
    {
        return CotizacionPasajero::where('cotizacion_id', $f['alternativa']->cotizacion_id)->orderBy('id')->get()
            ->reject(fn ($p) => in_array($p->id, $quitar, true))
            ->map(fn ($p) => ['id' => $p->id, 'edad' => $p->edad])->values()->all();
    }

    private function guardarPasajeros(array $f, array $quitar)
    {
        return app(CotizacionController::class)->actualizarPasajeros(
            new Request(['pasajeros' => $this->pasajerosSin($f, $quitar)]),
            (string) $f['alternativa']->cotizacion_id
        );
    }

    private function pasajeSolo(array $f, array $paxIds): AlternativaItem
    {
        $payload = $this->payloadBase();
        $payload['pax_incluidos'] = $paxIds;

        return AlternativaItem::findOrFail(app(AlternativaItemController::class)
            ->store(new Request($payload), (string) $f['alternativa']->id)->getData(true)['alternativa_item']['id']);
    }

    public function test_t075_borrar_al_unico_pasajero_de_un_pasaje_responde_422_y_no_toca_nada(): void
    {
        $f = $this->crearAlternativaConFamilia();
        $pasaje = $this->pasajeSolo($f, [$f['adulto2']->id]);

        $response = $this->guardarPasajeros($f, [$f['adulto2']->id]);

        $this->assertSame(422, $response->getStatusCode());
        $mensaje = $response->getData(true)['message'];
        $this->assertStringContainsString('Latam', $mensaje);
        $this->assertStringContainsString('Alternativa 1', $mensaje);
        $this->assertStringContainsString('pasajero 2 (adulto)', $mensaje);
        $this->assertSame(5, CotizacionPasajero::where('cotizacion_id', $f['alternativa']->cotizacion_id)->count());
        $this->assertSame([$f['adulto2']->id], $pasaje->fresh()->pax_incluidos);
    }

    public function test_t075_si_al_pasaje_le_queda_otro_pasajero_se_guarda_y_lo_conserva(): void
    {
        $f = $this->crearAlternativaConFamilia();
        $pasaje = $this->pasajeSolo($f, [$f['adulto1']->id, $f['adulto2']->id]);

        $response = $this->guardarPasajeros($f, [$f['adulto2']->id]);

        $this->assertSame(200, $response->getStatusCode(), json_encode($response->getData(true)));
        $this->assertSame([$f['adulto1']->id], $pasaje->fresh()->pax_incluidos);
    }

    public function test_t075_un_pasaje_de_una_alternativa_descartada_no_bloquea(): void
    {
        $f = $this->crearAlternativaConFamilia();
        $this->pasajeSolo($f, [$f['adulto2']->id]);
        $f['alternativa']->update(['estado' => 'descartada']);

        $this->assertSame(200, $this->guardarPasajeros($f, [$f['adulto2']->id])->getStatusCode());
    }
}
