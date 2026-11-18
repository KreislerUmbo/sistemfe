<?php

namespace Tests\Feature\AgenciaViajes\AltaItem;

use App\Models\AgenciaViajes\AlternativaItem;
use App\Services\AgenciaViajes\AltaItem\AltaGuia;
use App\Services\AgenciaViajes\AltaItem\AltaItemException;
use App\Services\HoraPeru;
use Tests\Feature\AgenciaViajes\AltaItem\Concerns\PreparaAltaItem;
use Tests\TestCase;

// T-095 — AltaGuia llamado directo (sin controller).
class AltaGuiaTest extends TestCase
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

    public function test_calcula_con_el_margen_de_la_tarifa_y_es_siempre_tarifa_fija(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');
        $tarifa = $this->tarifaGuia(['costo_diario' => 100, 'tipo_margen' => 'porcentaje', 'margen_valor' => 20]);

        $item = app(AltaGuia::class)->crear($alternativa, ['guia_tarifa_id' => $tarifa->id, 'cantidad' => 2, 'pax_incluidos' => [$pax[0]]]);

        $this->assertSame(AlternativaItem::ORIGEN_GUIA, $item->origen_tipo);
        $this->assertSame($tarifa->id, $item->guia_tarifa_id);
        $this->assertSame('tarifa_fija', $item->modo_precio);
        $this->assertSame('PEN', $item->moneda_costo);
        $this->assertEquals(100, $item->costo_snapshot);
        $this->assertEquals(120, $item->precio_venta_snapshot);
        $this->assertEquals(120, $item->precio_convertido);
        $this->assertSame([$pax[0]], $item->pax_incluidos);
        $this->assertEquals(240, $alternativa->fresh()->total); // 120 × cantidad 2
    }

    public function test_margen_fijo(): void
    {
        [$alternativa] = $this->alternativa();
        $tarifa = $this->tarifaGuia(['tipo_margen' => 'fijo', 'margen_valor' => 35]);

        $item = app(AltaGuia::class)->crear($alternativa, ['guia_tarifa_id' => $tarifa->id]);

        $this->assertEquals(135, $item->precio_venta_snapshot);
    }

    public function test_convierte_a_la_moneda_de_la_alternativa(): void
    {
        [$alternativa] = $this->alternativa('USD', 4);
        $tarifa = $this->tarifaGuia(['moneda' => 'PEN', 'costo_diario' => 100, 'margen_valor' => 20]);

        $item = app(AltaGuia::class)->crear($alternativa, ['guia_tarifa_id' => $tarifa->id]);

        $this->assertEquals(30, $item->precio_convertido); // 120 PEN / 4
    }

    public function test_tarifa_retirada_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa();
        $tarifa = $this->tarifaGuia(['activo' => false]);

        try {
            app(AltaGuia::class)->crear($alternativa, ['guia_tarifa_id' => $tarifa->id]);
            $this->fail('Debió lanzar AltaItemException');
        } catch (AltaItemException $e) {
            $this->assertSame('Esta tarifa fue retirada del catálogo activo — no se puede usar en un ítem nuevo.', $e->getMessage());
        }
        $this->assertSame(0, AlternativaItem::where('alternativa_id', $alternativa->id)->count());
    }

    public function test_tarifa_futura_se_rechaza_con_su_fecha(): void
    {
        [$alternativa] = $this->alternativa();
        $desde = \Carbon\Carbon::parse(HoraPeru::hoyTexto())->addDays(10);
        $tarifa = $this->tarifaGuia(['vigente_desde' => $desde->toDateString()]);

        $this->expectException(AltaItemException::class);
        $this->expectExceptionMessage('Esta tarifa todavía no está vigente (desde '.$desde->format('d/m/Y').').');
        app(AltaGuia::class)->crear($alternativa, ['guia_tarifa_id' => $tarifa->id]);
    }

    public function test_tarifa_vencida_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa();
        $tarifa = $this->tarifaGuia(['vigente_desde' => '2026-01-01', 'vigente_hasta' => '2026-02-01']);

        $this->expectException(AltaItemException::class);
        $this->expectExceptionMessage('Esta tarifa ya no está vigente: usa la versión actual.');
        app(AltaGuia::class)->crear($alternativa, ['guia_tarifa_id' => $tarifa->id]);
    }

    public function test_tarifa_inexistente_se_rechaza_en_la_validacion(): void
    {
        [$alternativa] = $this->alternativa();

        $this->expectException(AltaItemException::class);
        app(AltaGuia::class)->crear($alternativa, ['guia_tarifa_id' => 999999999]);
    }

    public function test_sin_tributario_cae_al_default_de_la_agencia(): void
    {
        [$alternativa] = $this->alternativa();
        $this->defaultTributarioDeLaAgencia('20', 'amazonia');
        $tarifa = $this->tarifaGuia();

        $item = app(AltaGuia::class)->crear($alternativa, ['guia_tarifa_id' => $tarifa->id]);

        $this->assertSame(['20', 'amazonia'], [$item->tip_afe_igv, $item->destino_tributario]);
    }

    public function test_destino_por_defecto_y_explicito(): void
    {
        [$alternativa] = $this->alternativa();
        $primero = $this->agregarDestino($alternativa, 1);
        $segundo = $this->agregarDestino($alternativa, 2);
        $tarifa = $this->tarifaGuia();

        $porDefecto = app(AltaGuia::class)->crear($alternativa, ['guia_tarifa_id' => $tarifa->id]);
        $explicito = app(AltaGuia::class)->crear($alternativa, ['guia_tarifa_id' => $tarifa->id, 'alternativa_destino_id' => $segundo]);

        $this->assertSame($primero, $porDefecto->alternativa_destino_id);
        $this->assertSame($segundo, $explicito->alternativa_destino_id);
    }
}
