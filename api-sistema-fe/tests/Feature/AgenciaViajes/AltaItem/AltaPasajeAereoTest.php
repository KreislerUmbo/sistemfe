<?php

namespace Tests\Feature\AgenciaViajes\AltaItem;

use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajeAereo;
use App\Services\AgenciaViajes\AltaItem\AltaItemException;
use App\Services\AgenciaViajes\AltaItem\AltaPasajeAereo;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\AltaItem\Concerns\PreparaAltaItem;
use Tests\TestCase;

// T-095 — AltaPasajeAereo llamado directo (sin controller). Pasajeros de la fixture: 2 adultos, 1 niño, 1 infante.
class AltaPasajeAereoTest extends TestCase
{
    use PreparaAltaItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
    }

    protected function tearDown(): void
    {
        $this->deshacerBase();
        parent::tearDown();
    }

    private function datos(array $extra = []): array
    {
        return $extra + [
            'aerolinea' => 'LATAM', 'itinerario' => 'LIMA - TARAPOTO', 'moneda' => 'PEN',
            'tarifa_base_adulto' => 500, 'tarifa_base_nino' => 450, 'tarifa_base_infante' => 200, 'fee_agencia_monto' => 300,
        ];
    }

    public function test_sin_pax_incluidos_cubre_a_todos_y_cobra_tarifas_y_fee_por_pasajero(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');

        $item = app(AltaPasajeAereo::class)->crear($alternativa, $this->datos());

        // 2×500 + 450 + 200 = 1650 de costo; fee 300 × 4 pasajeros (T-076) = 1200 → venta 2850.
        $this->assertSame($pax, $item->pax_incluidos); // T-007: guarda la lista, no null
        $this->assertSame('por_persona', $item->modo_precio);
        $this->assertSame(1, $item->cantidad);
        $this->assertEquals(1650, $item->costo_snapshot);
        $this->assertEquals(2850, $item->precio_venta_snapshot);
        $this->assertEquals(2850, $alternativa->fresh()->total);
    }

    public function test_crea_el_ítem_y_su_detalle_en_la_misma_transaccion(): void
    {
        [$alternativa] = $this->alternativa('PEN');

        $item = app(AltaPasajeAereo::class)->crear($alternativa, $this->datos([
            'cargos' => [['nombre' => 'TUA', 'monto' => 10, 'tipo' => 'tasa_aeropuerto']], 'tip_afe_igv' => '30',
        ]));

        $detalle = CotizacionPasajeAereo::where('alternativa_item_id', $item->id)->firstOrFail();
        $this->assertSame('LATAM', $detalle->aerolinea);
        $this->assertTrue((bool) $detalle->montos_por_pasajero);
        $this->assertSame('30', $detalle->tip_afe_igv);
        $this->assertEquals($item->costo_snapshot, $detalle->costo_total);
        $this->assertEquals($item->precio_venta_snapshot, $detalle->precio_venta_total);
        $this->assertNotNull($detalle->fecha_cotizado);
        $this->assertTrue($item->relationLoaded('cotizacionPasajeAereo'));
    }

    public function test_cargo_que_no_aplica_a_infantes_se_cobra_solo_a_adultos_y_ninos(): void
    {
        [$alternativa] = $this->alternativa('PEN');

        $calculo = app(AltaPasajeAereo::class)->calcular($alternativa, app(AltaPasajeAereo::class)->validar($this->datos([
            'tarifa_base_adulto' => 0, 'tarifa_base_nino' => 0, 'tarifa_base_infante' => 0, 'fee_agencia_monto' => 0,
            'cargos' => [['nombre' => 'IGV', 'monto' => 10, 'no_aplica_infante' => true], ['nombre' => 'TUA', 'monto' => 5]],
        ]), $alternativa));

        // IGV 10 × 3 (adultos y niño) + TUA 5 × 4 (todos) = 50.
        $this->assertEquals(50, $calculo['costo_total']);
    }

    public function test_fórmula_anterior_cobra_los_montos_del_grupo_una_vez(): void
    {
        [$alternativa] = $this->alternativa('PEN');

        $calculo = app(AltaPasajeAereo::class)->calcular($alternativa, app(AltaPasajeAereo::class)->validar($this->datos([
            'tarifa_base_adulto' => 0, 'tarifa_base_nino' => 0, 'tarifa_base_infante' => 0, 'fee_agencia_monto' => 100,
            'cargos' => [['nombre' => 'TUA', 'monto' => 20]], 'montos_por_pasajero' => false,
        ]), $alternativa));

        $this->assertEquals(20, $calculo['costo_total']);
        $this->assertEquals(120, $calculo['venta_total']);
    }

    public function test_con_pax_incluidos_parcial_solo_cuenta_a_los_marcados(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');

        $item = app(AltaPasajeAereo::class)->crear($alternativa, $this->datos(['pax_incluidos' => [$pax[0], $pax[2]]]));

        // 500 + 450 = 950; fee 300 × 2 = 600.
        $this->assertSame([$pax[0], $pax[2]], $item->pax_incluidos);
        $this->assertEquals(950, $item->costo_snapshot);
        $this->assertEquals(1550, $item->precio_venta_snapshot);
    }

    public function test_convierte_el_precio_a_la_moneda_de_la_alternativa(): void
    {
        [$alternativa] = $this->alternativa('PEN', 4);

        $item = app(AltaPasajeAereo::class)->crear($alternativa, $this->datos(['moneda' => 'USD', 'fee_agencia_monto' => 0, 'tarifa_base_nino' => 0, 'tarifa_base_infante' => 0]));

        $this->assertSame('USD', $item->moneda_costo);
        $this->assertEquals(1000, $item->precio_venta_snapshot); // 2 adultos × 500 USD
        $this->assertEquals(4000, $item->precio_convertido);
    }

    public function test_la_vigencia_se_guarda_en_utc_desde_la_hora_de_peru(): void
    {
        [$alternativa] = $this->alternativa('PEN');

        $item = app(AltaPasajeAereo::class)->crear($alternativa, $this->datos(['tarifa_valida_hasta' => '2026-12-31 18:30']));

        $guardado = DB::table('cotizacion_pasaje_aereo')->where('alternativa_item_id', $item->id)->value('tarifa_valida_hasta');
        $this->assertSame('2026-12-31 23:30:00', substr((string) $guardado, 0, 19)); // 18:30 en Perú = 23:30 UTC
    }

    public function test_aerolinea_en_minusculas_se_capitaliza(): void
    {
        [$alternativa] = $this->alternativa('PEN');

        $this->assertSame('Latam', app(AltaPasajeAereo::class)->validar($this->datos(['aerolinea' => 'latam']), $alternativa)['aerolinea']);
        $this->assertSame('LATAM', app(AltaPasajeAereo::class)->validar($this->datos(['aerolinea' => 'LATAM']), $alternativa)['aerolinea']);
    }

    public function test_sin_tributario_cae_al_default_de_la_agencia(): void
    {
        [$alternativa] = $this->alternativa('PEN');
        $this->defaultTributarioDeLaAgencia('20', 'amazonia');

        $item = app(AltaPasajeAereo::class)->crear($alternativa, $this->datos());

        $this->assertSame(['20', 'amazonia'], [$item->tip_afe_igv, $item->destino_tributario]);
    }

    public function test_destino_por_defecto_y_explicito(): void
    {
        [$alternativa] = $this->alternativa('PEN');
        $primero = $this->agregarDestino($alternativa, 1);
        $segundo = $this->agregarDestino($alternativa, 2);

        $this->assertSame($primero, app(AltaPasajeAereo::class)->crear($alternativa, $this->datos())->alternativa_destino_id);
        $this->assertSame($segundo, app(AltaPasajeAereo::class)->crear($alternativa, $this->datos(['alternativa_destino_id' => $segundo]))->alternativa_destino_id);
    }

    public function test_datos_invalidos_se_rechazan_y_no_dejan_nada(): void
    {
        [$alternativa] = $this->alternativa('PEN');

        try {
            app(AltaPasajeAereo::class)->crear($alternativa, $this->datos(['aerolinea' => null]));
            $this->fail('Debió lanzar AltaItemException');
        } catch (AltaItemException $e) {
            $this->assertSame('The aerolinea field is required.', $e->getMessage());
        }

        $this->assertSame(0, AlternativaItem::where('alternativa_id', $alternativa->id)->count());
        $this->assertSame(0, CotizacionPasajeAereo::count());
    }
}
