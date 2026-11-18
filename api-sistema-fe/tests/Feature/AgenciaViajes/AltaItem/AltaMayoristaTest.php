<?php

namespace Tests\Feature\AgenciaViajes\AltaItem;

use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;
use App\Services\AgenciaViajes\AltaItem\AltaItemException;
use App\Services\AgenciaViajes\AltaItem\AltaMayorista;
use Tests\Feature\AgenciaViajes\AltaItem\Concerns\PreparaAltaItem;
use Tests\TestCase;

// T-095 — AltaMayorista llamado directo (sin controller).
class AltaMayoristaTest extends TestCase
{
    use PreparaAltaItem;

    private const UUID = '5f0e5a52-7c3b-4c55-9b1f-0d2f3c4a5b6c';

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

    private function rechazo(callable $alta, string $mensaje): void
    {
        try {
            $alta();
            $this->fail('Debió lanzar AltaItemException');
        } catch (AltaItemException $e) {
            $this->assertSame($mensaje, $e->getMessage());
        }
    }

    public function test_con_tarifa_de_habitacion_toma_costo_y_venta_de_la_matriz(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN', 3.75);
        $opcion = $this->mayorista($alternativa);
        $tarifa = $this->tarifaHotel($opcion); // USD: costo 80, venta 120

        $item = app(AltaMayorista::class)->crear($alternativa, [
            'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id,
            'cantidad' => 2, 'pax_incluidos' => [$pax[0]], 'grupo_opcion_id' => self::UUID, 'dia_referencial' => 3,
        ]);

        $this->assertSame(AlternativaItem::ORIGEN_MAYORISTA, $item->origen_tipo);
        $this->assertSame($opcion->id, $item->opcion_mayorista_id);
        $this->assertSame($tarifa->id, $item->opcion_hotel_tarifa_id); // trazabilidad hacia la matriz
        $this->assertNull($item->opcion_mayorista_opcional_id);
        $this->assertSame('tarifa_fija', $item->modo_precio);
        $this->assertSame('USD', $item->moneda_costo);
        $this->assertEquals(80, $item->costo_snapshot);
        $this->assertEquals(120, $item->precio_venta_snapshot);
        $this->assertEquals(450, $item->precio_convertido); // 120 USD × 3.75
        $this->assertSame(self::UUID, $item->grupo_opcion_id);
        $this->assertSame(3, $item->dia_referencial);
        $this->assertSame(2, $item->cantidad);
    }

    public function test_con_opcional_el_costo_es_cero_y_la_moneda_es_la_del_opcional(): void
    {
        [$alternativa] = $this->alternativa('USD', 1);
        $opcion = $this->mayorista($alternativa);
        $opcional = OpcionMayoristaOpcional::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre' => 'San Blas', 'precio_por_persona' => 170, 'moneda' => 'USD',
        ]);

        $item = app(AltaMayorista::class)->crear($alternativa, ['opcion_mayorista_id' => $opcion->id, 'opcion_mayorista_opcional_id' => $opcional->id]);

        $this->assertSame($opcional->id, $item->opcion_mayorista_opcional_id);
        $this->assertNull($item->opcion_hotel_tarifa_id);
        $this->assertEquals(0, $item->costo_snapshot);
        $this->assertEquals(170, $item->precio_venta_snapshot);
        $this->assertEquals(170, $alternativa->fresh()->total);
    }

    public function test_hace_falta_una_de_las_dos_fuentes_de_precio(): void
    {
        [$alternativa] = $this->alternativa();
        $opcion = $this->mayorista($alternativa);

        $this->expectException(AltaItemException::class);
        app(AltaMayorista::class)->crear($alternativa, ['opcion_mayorista_id' => $opcion->id]);
    }

    public function test_opcion_de_otra_alternativa_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa();
        [$otra] = $this->alternativa();
        $opcion = $this->mayorista($otra);
        $tarifa = $this->tarifaHotel($opcion);

        $this->rechazo(
            fn () => app(AltaMayorista::class)->crear($alternativa, ['opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id]),
            'La opción de mayorista no pertenece a esta alternativa.'
        );
    }

    public function test_opcion_no_elegida_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa();
        $opcion = $this->mayorista($alternativa, 'propuesta');
        $tarifa = $this->tarifaHotel($opcion);

        $this->rechazo(
            fn () => app(AltaMayorista::class)->crear($alternativa, ['opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id]),
            'Marcá la opción de mayorista como elegida antes de agregarla al ítem.'
        );
        $this->assertSame(0, AlternativaItem::where('alternativa_id', $alternativa->id)->count());
    }

    public function test_tarifa_de_habitacion_de_otra_opcion_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa();
        $opcion = $this->mayorista($alternativa);
        $ajena = $this->tarifaHotel($this->mayorista($alternativa, 'propuesta'));

        $this->rechazo(
            fn () => app(AltaMayorista::class)->crear($alternativa, ['opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $ajena->id]),
            'Esa tarifa de habitación no pertenece a la opción de mayorista indicada.'
        );
    }

    public function test_opcional_de_otra_opcion_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa();
        $opcion = $this->mayorista($alternativa);
        $otra = $this->mayorista($alternativa, 'propuesta');
        $ajeno = OpcionMayoristaOpcional::create(['opcion_mayorista_id' => $otra->id, 'nombre' => 'Taboga', 'precio_por_persona' => 90, 'moneda' => 'USD']);

        $this->rechazo(
            fn () => app(AltaMayorista::class)->crear($alternativa, ['opcion_mayorista_id' => $opcion->id, 'opcion_mayorista_opcional_id' => $ajeno->id]),
            'Ese opcional no pertenece a la opción de mayorista indicada.'
        );
    }

    public function test_tributario_explicito_o_default_de_la_agencia(): void
    {
        [$alternativa] = $this->alternativa();
        $this->defaultTributarioDeLaAgencia('20', 'amazonia');
        $opcion = $this->mayorista($alternativa);
        $tarifa = $this->tarifaHotel($opcion);

        $conDefault = app(AltaMayorista::class)->crear($alternativa, ['opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id]);
        $explicito = app(AltaMayorista::class)->crear($alternativa, [
            'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id, 'tip_afe_igv' => '30', 'destino_tributario' => 'extranjero',
        ]);

        $this->assertSame(['20', 'amazonia'], [$conDefault->tip_afe_igv, $conDefault->destino_tributario]);
        $this->assertSame(['30', 'extranjero'], [$explicito->tip_afe_igv, $explicito->destino_tributario]);
    }

    public function test_destino_por_defecto_y_explicito(): void
    {
        [$alternativa] = $this->alternativa();
        $primero = $this->agregarDestino($alternativa, 1);
        $segundo = $this->agregarDestino($alternativa, 2);
        $opcion = $this->mayorista($alternativa);
        $tarifa = $this->tarifaHotel($opcion);
        $base = ['opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id];

        $this->assertSame($primero, app(AltaMayorista::class)->crear($alternativa, $base)->alternativa_destino_id);
        $this->assertSame($segundo, app(AltaMayorista::class)->crear($alternativa, $base + ['alternativa_destino_id' => $segundo])->alternativa_destino_id);
    }
}
