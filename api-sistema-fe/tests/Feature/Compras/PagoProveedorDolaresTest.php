<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Http\Controllers\Compras\CompraController;
use App\Http\Controllers\Compras\PagoProveedorController;
use App\Models\Compras\PagoProveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// T-140, parte A — dólares, pago en otra moneda y diferencia de cambio.
class PagoProveedorDolaresTest extends ComprasT140TestCase
{
    private function ficha(int $compraId): array
    {
        return app(CompraController::class)->show((string) $compraId)->getData(true)['compra'];
    }

    private function fichaPago(int $pagoId): array
    {
        return app(PagoProveedorController::class)->show((string) $pagoId)->getData(true)['pago'];
    }

    public function test_tipo_cambio_del_pago(): void // CA1
    {
        $this->rechazo(fn () => $this->porBanco('100.00', [], ['moneda' => 'USD']), 'En dólares se necesita el tipo de cambio.');
        $this->rechazo(fn () => $this->porBanco('100.00', [], ['moneda' => 'USD', 'tipo_cambio' => '0']), 'mayor a cero');
        $this->rechazo(fn () => $this->porBanco('100.00', [], ['moneda' => 'USD', 'tipo_cambio' => '3.70001']), 'cuatro decimales');

        $dolares = $this->porBanco('100.00', [], ['moneda' => 'USD', 'tipo_cambio' => '3.75']);
        $this->assertSame('3.7500', $dolares->fresh()->tipo_cambio);
        $soles = $this->porBanco('100.00', [], ['tipo_cambio' => '3.75']);
        $this->assertNull($soles->fresh()->tipo_cambio);
    }

    public function test_pagada_ahora_en_dolares(): void // CA2
    {
        $compra = $this->compra('500.00', [
            'moneda' => 'USD', 'tipo_cambio' => '3.7000',
            // El T.C. enviado en el pago no cuenta: manda el de la compra.
            'pago' => ['origen' => 'banco', 'cuenta_bancaria_id' => $this->cuentaUsd->id, 'numero_operacion' => 'T-USD', 'fecha' => self::HOY, 'tipo_cambio' => '9'],
        ]);

        $pago = $compra->pagos()->sole();
        $this->assertSame(['USD', '500.00', '3.7000'], [$pago->moneda, $pago->monto, $pago->tipo_cambio]);
        $this->assertEstado($compra, 'pagado', '0.00');
        $ficha = $this->ficha($compra->id);
        $this->assertSame('0.00', $ficha['aplicaciones_pago'][0]['diferencia_cambio']);
        $this->assertSame('0.00', $ficha['diferencia_cambio_total']);
    }

    public function test_pago_en_soles_a_compra_en_dolares(): void // CA3
    {
        $compra = $this->compraUsd('1000.00', '3.7000');
        $pago = $this->porBanco('3752.30', [[$compra, '1000.00', '3.7523']]);

        $aplicacion = $pago->aplicaciones()->sole();
        $this->assertSame(['3752.30', '1000.00', '3.7523'], [$aplicacion->monto, $aplicacion->monto_compra, $aplicacion->tipo_cambio]);
        $this->assertEstado($compra, 'pagado', '0.00');

        $ficha = $this->fichaPago($pago->id);
        $this->assertSame('0.00', $ficha['a_cuenta']);
        $this->assertSame('52.30', $ficha['aplicaciones'][0]['diferencia_cambio']);
        $this->assertSame('52.30', $this->ficha($compra->id)['diferencia_cambio_total']);
    }

    public function test_pago_en_dolares_a_compra_en_soles(): void // CA4
    {
        $compra = $this->compra('375.00');
        $pago = $this->porBanco('100.00', [[$compra, '375.00', '3.7500']], ['moneda' => 'USD', 'tipo_cambio' => '3.7500']);

        $aplicacion = $pago->aplicaciones()->sole();
        $this->assertSame(['100.00', '375.00', '3.7500'], [$aplicacion->monto, $aplicacion->monto_compra, $aplicacion->tipo_cambio]);
        $this->assertEstado($compra, 'pagado', '0.00');
        $this->assertNull($this->fichaPago($pago->id)['aplicaciones'][0]['diferencia_cambio']);
        $this->assertNull($this->ficha($compra->id)['diferencia_cambio_total']);
    }

    public function test_rechazos_en_otra_moneda(): void // CA5
    {
        $compra = $this->compraUsd('100.00');
        $libre = $this->porBanco('200.00');

        $this->rechazo(fn () => $this->aplicar($libre, $compra, '10.00'), "La compra {$compra->serie}-{$compra->numero} está en USD: indica el tipo de cambio.");
        $this->rechazo(fn () => $this->aplicar($libre, $compra, '10.00', '0'), 'mayor a cero');
        $e = $this->rechazo(fn () => $this->aplicar($libre, $compra, '100.01', '1'), 'solo se le pueden aplicar 100.00');
        $this->assertSame('100.00', $e->extra['maximo']);
        // 60 USD a 3.7 = 222 soles, y al pago le quedan 200.
        $this->rechazo(fn () => $this->aplicar($libre, $compra, '60.00', '3.7'), 'solo le quedan 200.00');
        // Un equivalente que redondea a cero no entra.
        $enDolares = $this->porBanco('10.00', [], ['moneda' => 'USD', 'tipo_cambio' => '3.7']);
        $soles = $this->compra('100.00');
        $this->rechazo(fn () => $this->aplicar($enDolares, $soles, '0.01', '3.7'), 'no llega a 0.01 USD');
    }

    public function test_conversion_redondea_una_vez(): void // CA6
    {
        $dolares = $this->compraUsd('100.00');
        $enSoles = $this->porBanco('1000.00', [[$dolares, '100.00', '3.3333']]);
        $this->assertSame('333.33', $enSoles->aplicaciones()->sole()->monto);

        $soles = $this->compra('100.00');
        $enDolares = $this->porBanco('100.00', [[$soles, '100.00', '3.3333']], ['moneda' => 'USD', 'tipo_cambio' => '3.3333']);
        $this->assertSame('30.00', $enDolares->aplicaciones()->sole()->monto);
        $this->assertEstado($soles, 'pagado', '0.00');
    }

    public function test_diferencia_de_cambio(): void // CA7
    {
        $perdida = $this->compraUsd('1000.00', '3.7000');
        $this->porBanco('1000.00', [[$perdida, '1000.00']], ['moneda' => 'USD', 'tipo_cambio' => '3.7500']);
        $this->assertSame('50.00', $this->ficha($perdida->id)['diferencia_cambio_total']);

        $ganancia = $this->compraUsd('1000.00', '3.7000');
        $this->porBanco('3650.00', [[$ganancia, '1000.00', '3.6500']]);
        $this->assertSame('-50.00', $this->ficha($ganancia->id)['diferencia_cambio_total']);

        // Un pago en USD anterior a T-140 no tiene T.C.
        $sinDato = $this->compraUsd('100.00', '3.7000');
        $pago = $this->porBanco('100.00', [[$sinDato, '100.00']], ['moneda' => 'USD', 'tipo_cambio' => '3.7500']);
        DB::table('pagos_proveedor')->where('id', $pago->id)->update(['tipo_cambio' => null]);
        $ficha = $this->ficha($sinDato->id);
        $this->assertNull($ficha['aplicaciones_pago'][0]['diferencia_cambio']);
        $this->assertNull($ficha['diferencia_cambio_total']);

        // Desaplicada ya no cuenta.
        $this->servicio()->desaplicar($pago->aplicaciones()->sole(), 'Prueba', $this->user);
        $this->assertNull($this->ficha($sinDato->id)['aplicaciones_pago'][0]['diferencia_cambio']);
    }

    public function test_migracion_conserva_lo_aplicado(): void // CA9
    {
        $compra = $this->compra('1000.00');
        $pago = $this->porBanco('600.00', [[$compra, '400.00']]);

        $migracion = require database_path('migrations/tenant/core/2026_12_02_100000_add_tipo_cambio_to_pagos_proveedor.php');
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('aplicaciones_pago_proveedor', 'monto_compra'));
        $migracion->up();

        $fila = DB::table('aplicaciones_pago_proveedor')->where('pago_proveedor_id', $pago->id)->first();
        $this->assertSame(['400.00', '400.00', null], [$fila->monto, $fila->monto_compra, $fila->tipo_cambio]);
        $this->assertNull(PagoProveedor::find($pago->id)->tipo_cambio);
        $this->assertEstado($compra, 'parcial', '600.00');
    }
}
