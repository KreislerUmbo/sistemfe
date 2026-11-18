<?php

namespace Tests\Feature\AgenciaViajes\AltaItem;

use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ProveedorAlojamientoDetalle;
use App\Services\AgenciaViajes\AltaItem\AltaItemException;
use App\Services\AgenciaViajes\AltaItem\AltaProveedor;
use Tests\Feature\AgenciaViajes\AltaItem\Concerns\PreparaAltaItem;
use Tests\TestCase;

// T-095 — AltaProveedor llamado directo (sin controller). Pasajeros de la fixture: 2 adultos (30, 28),
// 1 niño de 8 y 1 infante de 2.
class AltaProveedorTest extends TestCase
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

    private function rechazo(callable $alta, string $mensaje): void
    {
        try {
            $alta();
            $this->fail('Debió lanzar AltaItemException');
        } catch (AltaItemException $e) {
            $this->assertSame($mensaje, $e->getMessage());
        }
    }

    // ── Fuente 1: tarifa real ──────────────────────────────────────────

    public function test_tarifa_por_persona_cobra_a_los_pasajeros_marcados(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');
        $tarifa = $this->tarifaProveedor('compartido');

        $item = app(AltaProveedor::class)->crear($alternativa, [
            'proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'por_persona', 'pax_incluidos' => [$pax[0], $pax[2], $pax[3]], 'cantidad' => 2,
        ]);

        // adulto 150 + niño 90 + infante 30 = 270 de venta; costo 100 × 2 pasajeros que pagan (sin infante) = 200.
        $this->assertSame($tarifa->id, $item->proveedor_tarifa_id);
        $this->assertSame('por_persona', $item->modo_precio);
        $this->assertSame('PEN', $item->moneda_costo);
        $this->assertEquals(270, $item->precio_venta_snapshot);
        $this->assertEquals(270, $alternativa->fresh()->total); // por_persona: la cantidad no multiplica
        // El tributario sale de la tarifa, no del default de la agencia.
        $this->assertSame(['20', 'amazonia'], [$item->tip_afe_igv, $item->destino_tributario]);
    }

    public function test_tarifa_fija_con_cama_adicional_suma_costo_y_venta(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');
        $tarifa = $this->tarifaProveedor('privado', conAlojamiento: true);

        $item = app(AltaProveedor::class)->crear($alternativa, [
            'proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'tarifa_fija', 'pax_incluidos' => [$pax[2]], 'camas_adicionales_nino' => 2,
        ]);

        // habitación 150 + 2 × 30 = 210; costo 100 + 2 × 15 = 130.
        $this->assertEquals(210, $item->precio_venta_snapshot);
        $this->assertEquals(130, $item->costo_snapshot);
    }

    public function test_cama_adicional_se_ignora_en_modo_por_persona(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');
        $tarifa = $this->tarifaProveedor('compartido', conAlojamiento: true);

        $conCama = app(AltaProveedor::class)->crear($alternativa, [
            'proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'por_persona', 'pax_incluidos' => [$pax[0]], 'camas_adicionales_nino' => 3,
        ]);

        $this->assertEquals(150, $conCama->precio_venta_snapshot);
    }

    public function test_cama_adicional_sin_pasajero_en_el_tramo_de_edad_se_rechaza_por_edad_real(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');
        $tarifa = $this->tarifaProveedor('privado', conAlojamiento: true); // tramo (4, 12]

        $this->rechazo(
            fn () => app(AltaProveedor::class)->crear($alternativa, [
                'proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'tarifa_fija', 'pax_incluidos' => [$pax[3]], 'camas_adicionales_nino' => 1,
            ]),
            'Cama adicional solo aplica si pax_incluidos tiene al menos un pasajero dentro del tramo de edad de cama adicional de este proveedor.'
        );
        $this->assertSame(0, AlternativaItem::where('alternativa_id', $alternativa->id)->count());
    }

    public function test_cama_adicional_sin_pax_incluidos_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa('PEN');
        $tarifa = $this->tarifaProveedor('privado', conAlojamiento: true);

        $this->expectException(AltaItemException::class);
        app(AltaProveedor::class)->crear($alternativa, ['proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'tarifa_fija', 'camas_adicionales_nino' => 1]);
    }

    public function test_cama_adicional_sin_detalle_de_alojamiento_se_rechaza(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');
        $tarifa = $this->tarifaProveedor('privado'); // sin detalle de alojamiento

        $this->rechazo(
            fn () => app(AltaProveedor::class)->crear($alternativa, [
                'proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'tarifa_fija', 'pax_incluidos' => [$pax[2]], 'camas_adicionales_nino' => 1,
            ]),
            'Este proveedor no tiene configurado el detalle de alojamiento (tramo de edad para cama adicional).'
        );
    }

    public function test_cama_adicional_sin_precio_configurado_se_rechaza(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');
        $tarifa = $this->tarifaProveedor('privado');
        ProveedorAlojamientoDetalle::create([
            'proveedor_id' => $tarifa->proveedorServicio->proveedor_id, 'edad_max_infante_gratis' => 4, 'edad_max_nino_cama_adicional' => 12,
        ]);

        $this->rechazo(
            fn () => app(AltaProveedor::class)->crear($alternativa, [
                'proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'tarifa_fija', 'pax_incluidos' => [$pax[2]], 'camas_adicionales_nino' => 1,
            ]),
            'Esta tarifa no tiene precio de cama adicional configurado.'
        );
    }

    public function test_tarifa_retirada_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa('PEN');
        $tarifa = $this->tarifaProveedor('privado', extra: ['activo' => false]);

        $this->expectException(AltaItemException::class);
        app(AltaProveedor::class)->crear($alternativa, ['proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'tarifa_fija']);
    }

    public function test_tarifa_fuera_de_vigencia_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa('PEN');
        $tarifa = $this->tarifaProveedor('privado', extra: ['vigente_desde' => '2026-01-01', 'vigente_hasta' => '2026-02-01']);

        $this->expectException(AltaItemException::class);
        app(AltaProveedor::class)->crear($alternativa, ['proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'tarifa_fija']);
    }

    // ── Fuente 2: hotel ad-hoc local ───────────────────────────────────

    public function test_hotel_adhoc_es_siempre_por_habitacion_aunque_se_pida_por_persona(): void
    {
        [$alternativa] = $this->alternativa('PEN');
        $tarifa = $this->tarifaHotel(null, 'PEN'); // costo 80, venta 120

        $item = app(AltaProveedor::class)->crear($alternativa, ['opcion_hotel_tarifa_id' => $tarifa->id, 'modo_precio' => 'por_persona', 'cantidad' => 3]);

        $this->assertSame($tarifa->id, $item->opcion_hotel_tarifa_id);
        $this->assertNull($item->proveedor_tarifa_id);
        $this->assertSame('tarifa_fija', $item->modo_precio);
        $this->assertEquals(80, $item->costo_snapshot);
        $this->assertEquals(120, $item->precio_venta_snapshot);
        $this->assertSame(['10', 'nacional'], [$item->tip_afe_igv, $item->destino_tributario]);
        $this->assertEquals(360, $alternativa->fresh()->total);
    }

    public function test_hotel_adhoc_no_suma_cama_adicional(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');
        $tarifa = $this->tarifaHotel(null, 'PEN');

        $item = app(AltaProveedor::class)->crear($alternativa, [
            'opcion_hotel_tarifa_id' => $tarifa->id, 'modo_precio' => 'tarifa_fija', 'pax_incluidos' => [$pax[2]], 'camas_adicionales_nino' => 2,
        ]);

        $this->assertEquals(120, $item->precio_venta_snapshot);
    }

    // ── Fuente 3: precio de referencia ─────────────────────────────────

    public function test_precio_de_referencia_usa_lo_escrito_y_convierte_la_moneda(): void
    {
        [$alternativa] = $this->alternativa('PEN', 3.75);

        $item = app(AltaProveedor::class)->crear($alternativa, [
            'modo_precio' => 'tarifa_fija', 'moneda_costo' => 'USD', 'precio_venta_snapshot' => 50, 'costo_snapshot' => 30,
        ]);

        $this->assertNull($item->proveedor_tarifa_id);
        $this->assertNull($item->opcion_hotel_tarifa_id);
        $this->assertSame('USD', $item->moneda_costo);
        $this->assertEquals(30, $item->costo_snapshot);
        $this->assertEquals(187.5, $item->precio_convertido); // 50 USD × 3.75
    }

    public function test_precio_de_referencia_sin_costo_guarda_cero(): void
    {
        [$alternativa] = $this->alternativa('PEN');

        $item = app(AltaProveedor::class)->crear($alternativa, ['modo_precio' => 'tarifa_fija', 'moneda_costo' => 'PEN', 'precio_venta_snapshot' => 40]);

        $this->assertEquals(0, $item->costo_snapshot);
    }

    public function test_precio_de_referencia_sin_tributario_cae_al_default_y_el_escrito_se_respeta(): void
    {
        [$alternativa] = $this->alternativa('PEN');
        $this->defaultTributarioDeLaAgencia('20', 'amazonia');
        $base = ['modo_precio' => 'tarifa_fija', 'moneda_costo' => 'PEN', 'precio_venta_snapshot' => 40];

        $conDefault = app(AltaProveedor::class)->crear($alternativa, $base);
        $escrito = app(AltaProveedor::class)->crear($alternativa, $base + ['tip_afe_igv' => '30', 'destino_tributario' => 'extranjero']);

        $this->assertSame(['20', 'amazonia'], [$conDefault->tip_afe_igv, $conDefault->destino_tributario]);
        $this->assertSame(['30', 'extranjero'], [$escrito->tip_afe_igv, $escrito->destino_tributario]);
    }

    public function test_sin_ninguna_fuente_de_precio_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa('PEN');

        $this->expectException(AltaItemException::class);
        app(AltaProveedor::class)->crear($alternativa, ['modo_precio' => 'tarifa_fija']);
    }

    // ── Comunes ────────────────────────────────────────────────────────

    public function test_destino_por_defecto_explicito_y_ajeno(): void
    {
        [$alternativa] = $this->alternativa('PEN');
        $primero = $this->agregarDestino($alternativa, 1);
        $segundo = $this->agregarDestino($alternativa, 2);
        [$otra] = $this->alternativa('PEN');
        $ajeno = $this->agregarDestino($otra, 1);
        $base = ['modo_precio' => 'tarifa_fija', 'moneda_costo' => 'PEN', 'precio_venta_snapshot' => 40];

        $this->assertSame($primero, app(AltaProveedor::class)->crear($alternativa, $base)->alternativa_destino_id);
        $this->assertSame($segundo, app(AltaProveedor::class)->crear($alternativa, $base + ['alternativa_destino_id' => $segundo])->alternativa_destino_id);

        $this->expectException(AltaItemException::class);
        app(AltaProveedor::class)->crear($alternativa, $base + ['alternativa_destino_id' => $ajeno]);
    }

    public function test_el_grupo_de_opciones_se_guarda_y_debe_ser_uuid(): void
    {
        [$alternativa] = $this->alternativa('PEN');
        $base = ['modo_precio' => 'tarifa_fija', 'moneda_costo' => 'PEN', 'precio_venta_snapshot' => 40];
        $uuid = '5f0e5a52-7c3b-4c55-9b1f-0d2f3c4a5b6c';

        $this->assertSame($uuid, app(AltaProveedor::class)->crear($alternativa, $base + ['grupo_opcion_id' => $uuid])->grupo_opcion_id);

        $this->expectException(AltaItemException::class);
        app(AltaProveedor::class)->crear($alternativa, $base + ['grupo_opcion_id' => 'no-es-uuid']);
    }
}
