<?php

namespace Tests\Feature\AgenciaViajes\AltaItem;

use App\Models\AgenciaViajes\AlternativaItem;
use App\Services\AgenciaViajes\AltaItem\AltaItemException;
use App\Services\AgenciaViajes\AltaItem\AltaManual;
use Tests\Feature\AgenciaViajes\AltaItem\Concerns\PreparaAltaItem;
use Tests\TestCase;

// T-095 — AltaManual llamado directo (sin controller).
class AltaManualTest extends TestCase
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
            'descripcion_manual' => 'Entradas', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 20,
            'moneda_costo' => 'PEN', 'cantidad' => 3,
        ];
    }

    public function test_guarda_lo_escrito_sin_calcular_y_recalcula_el_total(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');

        $item = app(AltaManual::class)->crear($alternativa, $this->datos([
            'proveedor_sugerido_manual' => 'Proveedor X', 'pax_incluidos' => [$pax[0], $pax[1]], 'dia_referencial' => 2,
        ]));

        $this->assertSame(AlternativaItem::ORIGEN_MANUAL, $item->origen_tipo);
        $this->assertSame('Entradas', $item->descripcion_manual);
        $this->assertSame('Proveedor X', $item->proveedor_sugerido_manual);
        $this->assertSame('tarifa_fija', $item->modo_precio);
        $this->assertSame(3, $item->cantidad);
        $this->assertEquals(10, $item->costo_snapshot);
        $this->assertEquals(20, $item->precio_venta_snapshot);
        $this->assertSame([$pax[0], $pax[1]], $item->pax_incluidos);
        $this->assertSame(2, $item->dia_referencial);
        $this->assertEquals(60, $alternativa->fresh()->total);
    }

    public function test_convierte_el_precio_a_la_moneda_de_la_alternativa(): void
    {
        [$alternativa] = $this->alternativa('PEN', 3.75);

        $item = app(AltaManual::class)->crear($alternativa, $this->datos(['moneda_costo' => 'USD', 'cantidad' => 1]));

        $this->assertSame('USD', $item->moneda_costo);
        $this->assertEquals(75, $item->precio_convertido); // 20 USD × 3.75
        $this->assertEquals(75, $alternativa->fresh()->total);
    }

    public function test_sin_tributario_cae_al_default_de_la_agencia(): void
    {
        [$alternativa] = $this->alternativa();
        $this->defaultTributarioDeLaAgencia('20', 'amazonia');

        $item = app(AltaManual::class)->crear($alternativa, $this->datos());

        $this->assertSame('20', $item->tip_afe_igv);
        $this->assertSame('amazonia', $item->destino_tributario);
    }

    public function test_el_tributario_explicito_no_se_pisa_y_el_incompleto_se_completa(): void
    {
        [$alternativa] = $this->alternativa();
        $this->defaultTributarioDeLaAgencia('20', 'amazonia');

        $completo = app(AltaManual::class)->crear($alternativa, $this->datos(['tip_afe_igv' => '30', 'destino_tributario' => 'extranjero']));
        $this->assertSame(['30', 'extranjero'], [$completo->tip_afe_igv, $completo->destino_tributario]);

        $incompleto = app(AltaManual::class)->crear($alternativa, $this->datos(['tip_afe_igv' => '10']));
        $this->assertSame(['10', 'amazonia'], [$incompleto->tip_afe_igv, $incompleto->destino_tributario]);
    }

    public function test_destino_por_defecto_es_el_primero_de_la_alternativa(): void
    {
        [$alternativa] = $this->alternativa();
        $primero = $this->agregarDestino($alternativa, 1);
        $this->agregarDestino($alternativa, 2);

        $item = app(AltaManual::class)->crear($alternativa, $this->datos());

        $this->assertSame($primero, $item->alternativa_destino_id);
    }

    public function test_destino_explicito_de_la_alternativa_se_respeta_y_el_ajeno_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa();
        $this->agregarDestino($alternativa, 1);
        $segundo = $this->agregarDestino($alternativa, 2);

        $item = app(AltaManual::class)->crear($alternativa, $this->datos(['alternativa_destino_id' => $segundo]));
        $this->assertSame($segundo, $item->alternativa_destino_id);

        [$otra] = $this->alternativa();
        $ajeno = $this->agregarDestino($otra, 1);
        $this->expectException(AltaItemException::class);
        app(AltaManual::class)->crear($alternativa, $this->datos(['alternativa_destino_id' => $ajeno]));
    }

    public function test_sin_descripcion_se_rechaza_con_el_primer_error_y_no_guarda_nada(): void
    {
        [$alternativa] = $this->alternativa();

        try {
            app(AltaManual::class)->crear($alternativa, $this->datos(['descripcion_manual' => null]));
            $this->fail('Debió lanzar AltaItemException');
        } catch (AltaItemException $e) {
            $this->assertSame('The descripcion manual field is required.', $e->getMessage());
        }

        $this->assertSame(0, AlternativaItem::where('alternativa_id', $alternativa->id)->count());
    }

    public function test_cantidad_y_costo_son_obligatorios(): void
    {
        [$alternativa] = $this->alternativa();

        $this->expectException(AltaItemException::class);
        app(AltaManual::class)->crear($alternativa, ['descripcion_manual' => 'X', 'precio_venta_snapshot' => 5, 'moneda_costo' => 'PEN']);
    }
}
