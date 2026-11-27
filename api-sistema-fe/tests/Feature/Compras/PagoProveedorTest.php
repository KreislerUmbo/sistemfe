<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\Compras\Compra;
use App\Models\Compras\PagoProveedor;
use App\Models\CuentaBancaria;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Compras\CompraException;
use App\Services\Compras\CompraService;
use App\Services\Compras\PagoProveedorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Creditos\CreditosTestCase;

// T-136 — pagos de compra, contra Postgres real. «Hoy» es 2026-03-15 en Perú.
class PagoProveedorTest extends CreditosTestCase
{
    private const HOY = '2026-03-15';

    private User $user;

    private Proveedor $proveedor;

    private CuentaBancaria $cuenta;

    private int $numero = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoy(self::HOY);

        $this->user = $this->usuario(['compras.ver', 'compras.registrar', 'compras.anular', 'compras.pagar', 'cash.approve_expenses']);
        $this->proveedor = $this->proveedor('T136 Distribuidora SAC', '20136000001');
        $this->cuenta = $this->cuenta('PEN');
    }

    private function proveedor(string $nombre, string $ruc): Proveedor
    {
        return Proveedor::create(['razon_social' => $nombre, 'tipo_documento' => 'RUC', 'numero_documento' => $ruc]);
    }

    private function cuenta(?string $moneda): CuentaBancaria
    {
        return CuentaBancaria::create(['banco' => 'BCP', 'titular' => 'T136', 'numero_cuenta' => (string) random_int(1, 999999), 'moneda' => $moneda]);
    }

    private function datosCompra(string $total, array $cambios = []): array
    {
        return array_merge([
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante_codigo' => '01',
            'serie' => 'F136',
            'numero' => (string) ++$this->numero,
            'fecha_emision' => self::HOY,
            'moneda' => 'PEN',
            'base_gravada' => '0', 'igv' => '0', 'exonerado' => $total, 'inafecto' => '0', 'otros' => '0',
            'total' => $total,
            'condicion' => 'contado',
        ], $cambios);
    }

    private function compra(string $total = '1000.00', array $cambios = []): Compra
    {
        return app(CompraService::class)
            ->registrar(Request::create('/api/compras', 'POST', $this->datosCompra($total, $cambios)), $this->user->id);
    }

    private function nota(Compra $original, string $total): Compra
    {
        return $this->compra($total, [
            'tipo_comprobante_codigo' => '07',
            'compra_referencia_id' => $original->id,
            'proveedor_id' => $original->proveedor_id,
        ]);
    }

    private function servicio(): PagoProveedorService
    {
        return app(PagoProveedorService::class);
    }

    /** @param array<int, array{0: Compra, 1: string}> $aplicar */
    private function porBanco(string $monto, array $aplicar = [], array $cambios = []): PagoProveedor
    {
        return $this->servicio()->registrar(array_merge([
            'proveedor_id' => $this->proveedor->id,
            'origen' => 'banco',
            'moneda' => 'PEN',
            'monto' => $monto,
            'fecha' => self::HOY,
            'cuenta_bancaria_id' => $this->cuenta->id,
            'numero_operacion' => 'OP'.++$this->numero,
            'aplicaciones' => $this->filas($aplicar),
        ], $cambios), $this->user);
    }

    private function filas(array $aplicar): array
    {
        return array_map(fn (array $a) => ['compra_id' => $a[0]->id, 'monto' => $a[1]], $aplicar);
    }

    private function aplicar(PagoProveedor $pago, Compra $compra, string $monto): PagoProveedor
    {
        return $this->servicio()->aplicar($pago, $this->filas([[$compra, $monto]]), $this->user);
    }

    private function estado(Compra $compra): array
    {
        return $this->servicio()->estados(collect([$compra->fresh()]))[$compra->id];
    }

    private function assertEstado(Compra $compra, ?string $estado, ?string $saldo): void
    {
        $real = $this->estado($compra);
        $this->assertSame([$estado, $saldo], [$real['estado_pago'], $real['saldo']]);
    }

    private function rechazo(callable $accion, string $texto, int $status = 422): CompraException
    {
        $pagos = PagoProveedor::count();
        $aplicaciones = DB::table('aplicaciones_pago_proveedor')->count();
        try {
            $accion();
        } catch (CompraException $e) {
            $this->assertSame($status, $e->status, $e->getMessage());
            $this->assertStringContainsString($texto, $e->getMessage());
            $this->assertSame($pagos, PagoProveedor::count(), 'no debe guardarse nada');
            $this->assertSame($aplicaciones, DB::table('aplicaciones_pago_proveedor')->count());

            return $e;
        }
        $this->fail("Se esperaba el rechazo: {$texto}");
    }

    // ── Banco (CA6) ──────────────────────────────────────────────────

    public function test_pago_por_banco_sin_sesion_de_caja(): void
    {
        $pago = $this->porBanco('300.00', [], ['numero_operacion' => ' 00 123a ']);

        $this->assertSame('vigente', $pago->estado);
        $this->assertSame('00123A', $pago->numero_operacion);
        $this->assertSame($this->cuenta->id, $pago->cuenta_bancaria_id);
        $this->assertNull($pago->cash_movement_id);
        $this->assertSame(0, DB::table('cash_movements')->count());
    }

    public function test_numero_de_operacion_repetido_y_cuenta_sin_moneda_o_de_otra(): void
    {
        $primero = $this->porBanco('300.00', [], ['numero_operacion' => 'ABC1']);

        $e = $this->rechazo(fn () => $this->porBanco('50.00', [], ['numero_operacion' => 'abc 1']), 'ya está registrado', 409);
        $this->assertSame($primero->id, $e->extra['existente']['id']);

        // En otra cuenta sí, y anulado el primero también.
        $this->porBanco('50.00', [], ['numero_operacion' => 'ABC1', 'cuenta_bancaria_id' => $this->cuenta('PEN')->id]);
        $this->servicio()->anular($primero, 'Era otro', $this->user);
        $this->porBanco('300.00', [], ['numero_operacion' => 'ABC1']);

        $this->rechazo(fn () => $this->porBanco('50.00', [], ['cuenta_bancaria_id' => $this->cuenta(null)->id]), 'Completa la moneda');
        $this->rechazo(fn () => $this->porBanco('50.00', [], ['cuenta_bancaria_id' => $this->cuenta('USD')->id]), 'La cuenta es en USD');
        $inactiva = $this->cuenta('PEN');
        $inactiva->update(['activo' => false]);
        $this->rechazo(fn () => $this->porBanco('50.00', [], ['cuenta_bancaria_id' => $inactiva->id]), 'inactiva');
        $this->rechazo(fn () => $this->porBanco('50.00', [], ['numero_operacion' => '  ']), 'número de operación');
        $this->rechazo(fn () => $this->porBanco('50.00', [], ['fecha' => '2026-03-16']), 'no puede ser futura');
        $this->rechazo(fn () => $this->porBanco('0'), 'mayor a cero');
        $this->rechazo(fn () => $this->porBanco('1.005'), 'dos decimales');
        $this->rechazo(fn () => $this->porBanco('50.00', [], ['origen' => 'detraccion']), 'origen');
    }

    // ── Saldo y estado (CA7, CA8, CA9, CA10) ─────────────────────────

    public function test_pagos_parciales_hasta_pagar(): void
    {
        $compra = $this->compra('1000.00');
        $this->assertEstado($compra, 'pendiente', '1000.00');

        $this->porBanco('400.00', [[$compra, '400.00']]);
        $this->assertEstado($compra, 'parcial', '600.00');

        $this->porBanco('600.00', [[$compra, '600.00']]);
        $this->assertEstado($compra, 'pagado', '0.00');

        $this->rechazo(fn () => $this->porBanco('1.00', [[$compra, '1.00']]), 'solo se le pueden aplicar 0.00');
    }

    public function test_lo_no_aplicado_queda_a_cuenta(): void
    {
        $primera = $this->compra('1000.00');
        $segunda = $this->compra('800.00');
        $pago = $this->porBanco('1500.00', [[$primera, '1000.00']]);
        $this->assertSame('1000.00', (string) $this->servicio()->aplicadoDe($pago));

        $this->rechazo(fn () => $this->aplicar($pago, $segunda, '500.01'), 'solo le quedan 500.00');
        $this->aplicar($pago, $segunda, '500.00');
        $this->assertEstado($segunda, 'parcial', '300.00');

        $otro = $this->proveedor('T136 Otro SAC', '20136000002');
        $ajena = $this->compra('100.00', ['proveedor_id' => $otro->id]);
        $dolares = $this->compra('100.00', ['moneda' => 'USD', 'tipo_cambio' => '3.7']);
        $libre = $this->porBanco('100.00');
        $this->rechazo(fn () => $this->aplicar($libre, $ajena, '10.00'), 'de otro proveedor');
        $this->rechazo(fn () => $this->aplicar($libre, $dolares, '10.00'), 'está en USD');

        $nc = $this->nota($this->compra('50.00'), '10.00');
        $this->rechazo(fn () => $this->aplicar($libre, $nc, '5.00'), 'nota de crédito');
        $anulada = $this->compra('50.00');
        app(CompraService::class)->anular($anulada, 'Duplicada', $this->user->id);
        $this->rechazo(fn () => $this->aplicar($libre, $anulada, '5.00'), 'anulada');
        $this->rechazo(
            fn () => $this->servicio()->aplicar($libre, $this->filas([[$segunda, '1.00'], [$segunda, '1.00']]), $this->user),
            'repetida'
        );
    }

    public function test_vencido_segun_la_fecha_de_peru(): void
    {
        $credito = $this->compra('100.00', ['condicion' => 'credito', 'fecha_emision' => '2026-03-01', 'fecha_vencimiento' => '2026-03-14']);
        $contado = $this->compra('100.00', ['fecha_emision' => '2026-03-14']);
        $venceHoy = $this->compra('100.00', ['condicion' => 'credito', 'fecha_emision' => '2026-03-01', 'fecha_vencimiento' => self::HOY]);

        $this->assertEstado($credito, 'vencido', '100.00');
        $this->assertEstado($contado, 'vencido', '100.00');
        $this->assertEstado($venceHoy, 'pendiente', '100.00');

        // 23:00 del 15 en Lima ya es 16 en UTC: sigue sin vencer.
        $this->hoy(self::HOY, '23:00:00');
        $this->assertEstado($venceHoy, 'pendiente', '100.00');
    }

    public function test_la_nota_de_credito_baja_el_saldo(): void
    {
        $sinPagos = $this->compra('1000.00');
        $this->nota($sinPagos, '200.00');
        $this->assertEstado($sinPagos, 'pendiente', '800.00');
        $this->rechazo(fn () => $this->porBanco('900.00', [[$sinPagos, '800.01']]), 'aplicar 800.00');

        $pagada = $this->compra('1000.00');
        $this->porBanco('1000.00', [[$pagada, '1000.00']]);
        $nc = $this->nota($pagada, '200.00');
        $this->assertEstado($pagada, 'pagado', '-200.00');
        $this->assertEstado($nc, null, null);

        app(CompraService::class)->anular($nc, 'No correspondía', $this->user->id);
        $this->assertEstado($pagada, 'pagado', '0.00');
    }

    public function test_desaplicar_y_anular_no_borran_nada(): void
    {
        $compra = $this->compra('1000.00');
        $pago = $this->porBanco('1000.00', [[$compra, '1000.00']]);
        $aplicacion = $pago->aplicaciones()->first();

        $this->rechazo(fn () => $this->servicio()->desaplicar($aplicacion, '  ', $this->user), 'motivo');
        $this->servicio()->desaplicar($aplicacion, 'Compra equivocada', $this->user);
        $this->assertEstado($compra, 'pendiente', '1000.00');
        $this->assertNotNull($aplicacion->fresh()->desaplicada_at);
        $this->assertSame($this->user->id, $aplicacion->fresh()->desaplicada_por);
        $this->rechazo(fn () => $this->servicio()->desaplicar($aplicacion, 'Otra vez', $this->user), 'ya está desaplicado');

        $this->aplicar($pago, $compra, '1000.00');
        $this->servicio()->anular($pago, 'No salió', $this->user);
        $this->assertEstado($compra, 'pendiente', '1000.00');
        $this->assertSame('anulado', $pago->fresh()->estado);
        $this->assertSame('No salió', $pago->fresh()->motivo_anulacion);
        $this->assertSame(2, $pago->aplicaciones()->count());
        $this->assertSame(0, $pago->aplicaciones()->activas()->count());
        $this->rechazo(fn () => $this->servicio()->anular($pago, 'Otra vez', $this->user), 'ya está anulado');
        $this->rechazo(fn () => $this->aplicar($pago, $compra, '1.00'), 'está anulado');
    }

    // ── Concurrencia (CA16) ──────────────────────────────────────────

    public function test_el_saldo_se_revisa_con_las_compras_bloqueadas_en_orden_de_id(): void
    {
        $primera = $this->compra('100.00');
        $segunda = $this->compra('100.00');

        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $consultas[] = $q->sql;
        });
        $this->porBanco('100.00', [[$segunda, '40.00'], [$primera, '60.00']]);

        $bloqueo = $this->posicion($consultas, '/from "compras".*order by "id" asc.*for update/');
        $saldo = $this->posicion($consultas, '/from "aplicaciones_pago_proveedor" as "a"/');
        $this->assertLessThan($saldo, $bloqueo, 'primero se bloquea y recién se calcula el saldo');

        // El segundo pago ve lo que dejó el primero: no se supera el total.
        $this->rechazo(fn () => $this->porBanco('100.00', [[$segunda, '60.01']]), 'aplicar 60.00');
    }

    private function posicion(array $consultas, string $patron): int
    {
        foreach ($consultas as $i => $sql) {
            if (preg_match($patron, $sql)) {
                return $i;
            }
        }
        $this->fail("No se ejecutó ninguna consulta que cumpla {$patron}:\n".implode("\n", $consultas));
    }

    public function test_cuenta_y_proveedor_con_pagos_no_se_borran(): void
    {
        $this->porBanco('10.00');
        $sinPagos = $this->cuenta('PEN');

        $controller = app(\App\Http\Controllers\CuentaBancariaController::class);
        $respuesta = $controller->destroy((string) $this->cuenta->id);
        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('Desactívala', $respuesta->getData(true)['message']);
        $this->assertSame(200, $controller->destroy((string) $sinPagos->id)->getStatusCode());

        $proveedores = app(\App\Http\Controllers\AgenciaViajes\ProveedorController::class);
        $this->assertSame(422, $proveedores->destroy((string) $this->proveedor->id)->getStatusCode());
    }
}
