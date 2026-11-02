<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Services\AgenciaViajes\AlternativaCopiaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-076 — cargos y fee del pasaje aéreo POR PASAJERO. Los pasajes guardados antes conservan su
// fórmula (cotizacion_pasaje_aereo.montos_por_pasajero = false). Postgres real, transacción por
// test revertida.
class PasajeAereoPorPasajeroTest extends TestCase
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

    /** Alternativa con 2 adultos y 1 infante. */
    private function crearAlternativa(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667799', 'full_name' => 'Cliente Test Pasaje Por Pasajero',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0760-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adulto1 = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 36]);
        $adulto2 = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 37]);
        $infante = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'infante', 'edad' => 1]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'USD', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        return compact('alternativa', 'adulto1', 'adulto2', 'infante');
    }

    /** Tarifa 100 para todos, TUA 20, fee 10. */
    private function payload(array $pax): array
    {
        return [
            'origen_tipo' => 'pasaje_aereo', 'aerolinea' => 'LATAM', 'moneda' => 'USD',
            'tarifa_base_adulto' => 100, 'tarifa_base_nino' => 100, 'tarifa_base_infante' => 100,
            'cargos' => [['nombre' => 'TUA', 'monto' => 20, 'tipo' => 'tasa_aeropuerto']],
            'fee_agencia_monto' => 10, 'dia_referencial' => 1, 'pax_incluidos' => $pax,
        ];
    }

    private function crear(array $f, array $payload): array
    {
        return app(AlternativaItemController::class)
            ->store(new Request($payload), (string) $f['alternativa']->id)->getData(true)['alternativa_item'];
    }

    private function marca(int $itemId): bool
    {
        return (bool) DB::table('cotizacion_pasaje_aereo')->where('alternativa_item_id', $itemId)->value('montos_por_pasajero');
    }

    /** Deja un pasaje como los guardados antes de T-076: marca en false y montos ya del grupo. */
    private function volverPasajeAnterior(int $itemId): void
    {
        DB::table('cotizacion_pasaje_aereo')->where('alternativa_item_id', $itemId)->update([
            'montos_por_pasajero' => false,
            'cargos' => json_encode([['nombre' => 'TUA', 'monto' => 40, 'tipo' => 'tasa_aeropuerto']]),
            'fee_agencia_monto' => 20, 'costo_total' => 240, 'precio_venta_total' => 260,
        ]);
    }

    /** Lo que manda el formulario al editar el pasaje anterior: los montos del grupo. */
    private function payloadPasajeAnterior(array $f): array
    {
        $payload = $this->payload([$f['adulto1']->id, $f['adulto2']->id]);
        $payload['cargos'] = [['nombre' => 'TUA', 'monto' => 40, 'tipo' => 'tasa_aeropuerto']];
        $payload['fee_agencia_monto'] = 20;

        return $payload;
    }

    /** CA1 — 2 adultos, tarifa 100, TUA 20, fee 10 → costo 200+40 = 240, venta 240+20 = 260. */
    public function test_ca1_cargos_y_fee_se_multiplican_por_los_pasajeros_marcados(): void
    {
        $f = $this->crearAlternativa();

        $item = $this->crear($f, $this->payload([$f['adulto1']->id, $f['adulto2']->id]));

        $this->assertEquals(240.0, (float) $item['costo_snapshot']);
        $this->assertEquals(260.0, (float) $item['precio_venta_snapshot']);
        $this->assertTrue($this->marca($item['id']));
    }

    /** CA2 — «no aplica a infantes»: el cargo va por adultos y niños; el fee, por todos. */
    public function test_ca2_cargo_que_no_aplica_a_infantes_no_se_cobra_por_el_infante(): void
    {
        $f = $this->crearAlternativa();
        $payload = $this->payload([$f['adulto1']->id, $f['adulto2']->id, $f['infante']->id]);
        $payload['cargos'][0]['no_aplica_infante'] = true;

        $item = $this->crear($f, $payload);

        // Tarifas 3×100 = 300; TUA 20 × 2 (sin el infante) = 40 → costo 340; fee 10 × 3 = 30 → venta 370.
        $this->assertEquals(340.0, (float) $item['costo_snapshot']);
        $this->assertEquals(370.0, (float) $item['precio_venta_snapshot']);
    }

    public function test_cargo_sin_la_marca_se_cobra_tambien_al_infante(): void
    {
        $f = $this->crearAlternativa();

        $item = $this->crear($f, $this->payload([$f['adulto1']->id, $f['infante']->id]));

        // Tarifas 200 + TUA 20×2 = 240 de costo; fee 10×2 = 20 → venta 260.
        $this->assertEquals(240.0, (float) $item['costo_snapshot']);
        $this->assertEquals(260.0, (float) $item['precio_venta_snapshot']);
    }

    /** CA3 — un pasaje anterior, al editarlo sin pedir el cambio, da el mismo costo y venta que antes. */
    public function test_ca3_pasaje_anterior_conserva_su_formula_al_editarlo(): void
    {
        $f = $this->crearAlternativa();
        $item = $this->crear($f, $this->payload([$f['adulto1']->id, $f['adulto2']->id]));
        $this->volverPasajeAnterior($item['id']);

        // Sin mandar la marca: el pasaje conserva la suya.
        $payload = $this->payloadPasajeAnterior($f);
        $editado = app(AlternativaItemController::class)
            ->actualizarPasajeAereo(new Request($payload), (string) $item['id'])->getData(true)['alternativa_item'];

        $this->assertEquals(240.0, (float) $editado['costo_snapshot']);
        $this->assertEquals(260.0, (float) $editado['precio_venta_snapshot']);
        $this->assertFalse($this->marca($item['id']));

        // El preview del formulario, mandando la marca que trae el pasaje.
        $preview = app(AlternativaItemController::class)
            ->previewPasajeAereo(new Request($payload + ['montos_por_pasajero' => false]), (string) $f['alternativa']->id)
            ->getData(true)['resultado'];
        $this->assertEquals(260.0, (float) $preview['venta_total']);
    }

    /** El vendedor puede pedir pasar un pasaje anterior a montos por pasajero. */
    public function test_pasaje_anterior_pasa_a_la_formula_nueva_solo_si_se_pide(): void
    {
        $f = $this->crearAlternativa();
        $item = $this->crear($f, $this->payload([$f['adulto1']->id, $f['adulto2']->id]));
        $this->volverPasajeAnterior($item['id']);

        // Con montos por pasajero (TUA 20, fee 10) y 2 adultos el resultado coincide: 240 / 260.
        $payload = $this->payload([$f['adulto1']->id, $f['adulto2']->id]) + ['montos_por_pasajero' => true];
        $editado = app(AlternativaItemController::class)
            ->actualizarPasajeAereo(new Request($payload), (string) $item['id'])->getData(true)['alternativa_item'];

        $this->assertEquals(260.0, (float) $editado['precio_venta_snapshot']);
        $this->assertTrue($this->marca($item['id']));
    }

    /** CA4 — duplicar la alternativa copia la marca de cada pasaje y el precio no cambia. */
    public function test_ca4_duplicar_la_alternativa_copia_la_marca(): void
    {
        $f = $this->crearAlternativa();
        $payload = $this->payload([$f['adulto1']->id, $f['adulto2']->id]);
        $nuevo = $this->crear($f, $payload);
        $anterior = $this->crear($f, $payload);
        $this->volverPasajeAnterior($anterior['id']);

        [, $mapa] = app(AlternativaCopiaService::class)->copiar($f['alternativa']->fresh(), $f['alternativa']->cotizacion_id, 'Copia');

        $this->assertTrue($this->marca($mapa[$nuevo['id']]->id));
        $this->assertFalse($this->marca($mapa[$anterior['id']]->id));
        foreach ([$nuevo, $anterior] as $original) {
            $this->assertEquals(
                DB::table('cotizacion_pasaje_aereo')->where('alternativa_item_id', $original['id'])->value('precio_venta_total'),
                DB::table('cotizacion_pasaje_aereo')->where('alternativa_item_id', $mapa[$original['id']]->id)->value('precio_venta_total')
            );
        }
    }

    /** CA6 — un pasaje viejo con un cargo fee_agencia se guarda sin error. */
    public function test_ca6_pasaje_viejo_con_cargo_fee_agencia_se_guarda_sin_error(): void
    {
        $f = $this->crearAlternativa();
        $item = $this->crear($f, $this->payload([$f['adulto1']->id, $f['adulto2']->id]));
        $this->volverPasajeAnterior($item['id']);

        $payload = $this->payloadPasajeAnterior($f);
        $payload['cargos'] = [['nombre' => 'Fee', 'monto' => 15, 'tipo' => 'fee_agencia']];

        $response = app(AlternativaItemController::class)->actualizarPasajeAereo(new Request($payload), (string) $item['id']);

        $this->assertSame(200, $response->getStatusCode());
    }
}
