<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
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

        // 2 adultos*500 + 2 niños*450 + 1 infante*200 = 1000+900+200 = 2100, + fee 300 = 2400.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertEquals(2100.0, (float) $body['alternativa_item']['costo_snapshot']);
        $this->assertEquals(2400.0, (float) $body['alternativa_item']['precio_venta_snapshot']);
        $this->assertNull($body['alternativa_item']['pax_incluidos']);
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

        // 2 adultos*500 + 1 niño*450 + 0 infantes = 1000+450 = 1450, + fee 300 = 1750.
        $this->assertEquals(1450.0, (float) $body['alternativa_item']['costo_snapshot']);
        $this->assertEquals(1750.0, (float) $body['alternativa_item']['precio_venta_snapshot']);
        $this->assertSame([$f['adulto1']->id, $f['adulto2']->id, $f['nino1']->id], $body['alternativa_item']['pax_incluidos']);
    }

    public function test_crear_con_infante_incluido_suma_su_tarifa(): void
    {
        $f = $this->crearAlternativaConFamilia();

        $payload = $this->payloadBase();
        $payload['pax_incluidos'] = [$f['adulto1']->id, $f['infante']->id];

        $response = app(AlternativaItemController::class)->store(new Request($payload), (string) $f['alternativa']->id);
        $body = $response->getData(true);

        // 1 adulto*500 + 1 infante*200 = 700, + fee 300 = 1000.
        $this->assertEquals(700.0, (float) $body['alternativa_item']['costo_snapshot']);
        $this->assertEquals(1000.0, (float) $body['alternativa_item']['precio_venta_snapshot']);
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
        // 2 adultos*500 + 1 niño*470 = 1470, + fee 300 = 1770.
        $this->assertEquals(1470.0, (float) $body['alternativa_item']['costo_snapshot']);
        $this->assertEquals(1770.0, (float) $body['alternativa_item']['precio_venta_snapshot']);

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
}
