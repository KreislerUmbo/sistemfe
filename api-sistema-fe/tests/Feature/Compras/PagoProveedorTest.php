<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Http\Controllers\Cash\CashMovementController;
use App\Http\Controllers\Compras\CompraController;
use App\Http\Controllers\Compras\PagoProveedorController;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Company;
use App\Models\Compras\Compra;
use App\Models\Compras\PagoProveedor;
use App\Models\CuentaBancaria;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Compras\CompraException;
use App\Services\Compras\CompraService;
use App\Services\Compras\PagoProveedorService;
use App\Services\ExpectedCashCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    // ── Caja (CA3, CA4, CA5, CA11, CA12, CA13) ──────────────────────

    /** Caja abierta de quien está usando el sistema, con un fondo en efectivo. */
    private function cajaConFondo(string $fondo = '2000.00'): CashSession
    {
        $sesion = $this->abrirCaja($this->user);
        CashMovement::create([
            'cash_session_id' => $sesion->id, 'type' => 'opening_fund', 'payment_method_id' => $this->efectivo->id,
            'direction' => 'in', 'amount' => $fondo, 'currency' => 'PEN', 'status' => 'confirmed', 'created_by' => $this->user->id,
        ]);

        return $sesion;
    }

    private function esperado(CashSession $sesion): float
    {
        return app(ExpectedCashCalculator::class)->compute($sesion);
    }

    private function porCaja(string $monto, array $aplicar = [], array $cambios = []): PagoProveedor
    {
        return $this->servicio()->registrar(array_merge([
            'proveedor_id' => $this->proveedor->id,
            'origen' => 'caja',
            'moneda' => 'PEN',
            'monto' => $monto,
            'payment_method_id' => $this->efectivo->id,
            'aplicaciones' => $this->filas($aplicar),
        ], $cambios), $this->user);
    }

    private function exigirAprobacionSobre(string $monto): void
    {
        $datos = ['require_expense_approval' => true, 'max_expense_without_approval' => $monto];
        $empresa = Company::first() ?? Company::create(['razon_social' => 'Empresa T136 SAC', 'razon_social_comercial' => 'Empresa T136', 'n_document' => '20136999999']);
        $empresa->update($datos);
    }

    private function rechazoDeCaja(callable $accion, string $texto, int $status = 422): void
    {
        $antes = [PagoProveedor::count(), CashMovement::count()];
        try {
            $accion();
            $this->fail("Se esperaba el rechazo: {$texto}");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());
            $this->assertStringContainsString($texto, $e->getMessage());
        }
        $this->assertSame($antes, [PagoProveedor::count(), CashMovement::count()], 'no debe guardarse nada');
    }

    public function test_pago_desde_caja_registra_egreso(): void
    {
        $sesion = $this->cajaConFondo();
        $compra = $this->compra('500.00');

        // La fecha enviada no cuenta: en Caja es hoy en Perú, también de noche.
        $this->hoy(self::HOY, '23:30:00');
        $pago = $this->porCaja('500.00', [[$compra, '500.00']], ['fecha' => '2026-01-01']);

        $this->assertSame('vigente', $pago->estado);
        $this->assertSame(self::HOY, $pago->fecha->toDateString());
        $movimiento = $pago->movimiento;
        $this->assertSame(
            ['pago_proveedor', 'out', '500.00', 'pago_proveedor', $pago->id, 'proveedor', $this->proveedor->id, 'confirmed', $sesion->id],
            [$movimiento->type, $movimiento->direction, $movimiento->amount, $movimiento->reference_type, (int) $movimiento->reference_id,
                $movimiento->counterparty_type, (int) $movimiento->counterparty_id, $movimiento->status, (int) $movimiento->cash_session_id]
        );
        $this->assertSame($this->proveedor->razon_social, $movimiento->counterparty_name);
        $this->assertSame(1500.0, $this->esperado($sesion));
        $this->assertEstado($compra, 'pagado', '0.00');
    }

    public function test_pago_desde_caja_sin_sesion_abierta(): void
    {
        $compra = $this->compra('500.00');

        $this->rechazoDeCaja(fn () => $this->porCaja('500.00', [[$compra, '500.00']]), 'No hay una sesión de caja abierta');
        $this->assertEstado($compra, 'pendiente', '500.00');
    }

    public function test_pago_desde_caja_no_deja_el_efectivo_en_negativo(): void
    {
        $this->cajaConFondo('100.00');

        $this->rechazoDeCaja(fn () => $this->porCaja('100.01'), 'en negativo');
        $this->rechazoDeCaja(fn () => $this->porCaja('1.00', [], ['moneda' => 'USD']), 'en negativo');
    }

    public function test_si_falla_la_aplicacion_no_sale_dinero(): void
    {
        $this->cajaConFondo();
        $compra = $this->compra('100.00');
        $movimientos = CashMovement::count();

        $this->rechazo(fn () => $this->porCaja('200.00', [[$compra, '200.00']]), 'solo se le pueden aplicar');
        $this->assertSame($movimientos, CashMovement::count());
    }

    public function test_egreso_sobre_el_umbral_espera_aprobacion(): void
    {
        $sesion = $this->cajaConFondo();
        $this->exigirAprobacionSobre('500.00');
        $compra = $this->compra('800.00');

        $pago = $this->porCaja('800.00', [[$compra, '800.00']]);

        $this->assertSame('pendiente_aprobacion', $pago->estado);
        $this->assertSame('pending_approval', $pago->movimiento->status);
        $this->assertSame(2000.0, $this->esperado($sesion));
        $this->assertEstado($compra, 'pendiente', '800.00');
        $this->assertSame('800.00', $this->estado($compra)['por_aprobar']);
        // El saldo queda reservado.
        $this->rechazo(fn () => $this->porBanco('1.00', [[$compra, '1.00']]), 'aplicar 0.00');

        app(CashMovementController::class)->approve((string) $pago->cash_movement_id);

        $this->assertSame('vigente', $pago->fresh()->estado);
        $this->assertSame(1200.0, $this->esperado($sesion));
        $this->assertEstado($compra, 'pagado', '0.00');
    }

    public function test_egreso_rechazado_anula_el_pago(): void
    {
        $this->cajaConFondo();
        $this->exigirAprobacionSobre('500.00');
        $compra = $this->compra('800.00');
        $pago = $this->porCaja('800.00', [[$compra, '800.00']]);

        app(CashMovementController::class)->reject((string) $pago->cash_movement_id);

        $pago->refresh();
        $this->assertSame(['anulado', 'Egreso rechazado en Caja', $this->user->id], [$pago->estado, $pago->motivo_anulacion, (int) $pago->anulado_por]);
        $this->assertSame('rejected', $pago->movimiento->status);
        $this->assertSame(0, $pago->aplicaciones()->activas()->count());
        $this->assertEstado($compra, 'pendiente', '800.00');
    }

    public function test_anular_un_pago_por_aprobar_rechaza_el_egreso(): void
    {
        $this->cajaConFondo();
        $this->exigirAprobacionSobre('500.00');
        $pago = $this->porCaja('800.00');

        $this->servicio()->anular($pago, 'Se pagará por banco', $this->user);

        $this->assertSame('rejected', $pago->fresh()->movimiento->status);
        $this->assertSame(0, CashMovement::where('type', 'correction')->count());
        $this->rechazoDeCaja(fn () => app(CashMovementController::class)->approve((string) $pago->cash_movement_id), 'no está pendiente');
    }

    public function test_anular_un_pago_de_caja_corrige_el_egreso(): void
    {
        $sesion = $this->cajaConFondo();
        $compra = $this->compra('500.00');
        $pago = $this->porCaja('500.00', [[$compra, '500.00']]);

        $this->servicio()->anular($pago, 'Pago duplicado', $this->user);

        $original = $pago->fresh()->movimiento;
        $this->assertSame(['confirmed', '500.00', $this->user->id], [$original->status, $original->amount, (int) $original->corrected_by]);
        $correccion = CashMovement::where('type', 'correction')->sole();
        $this->assertSame([$original->id, 'in', '500.00'], [(int) $correccion->corrected_movement_id, $correccion->direction, $correccion->amount]);
        $this->assertSame(2000.0, $this->esperado($sesion));
        $this->assertSame('anulado', $pago->fresh()->estado);
        $this->assertEstado($compra, 'pendiente', '500.00');
    }

    public function test_anular_sin_caja_abierta_no_cambia_nada(): void
    {
        $sesion = $this->cajaConFondo();
        $compra = $this->compra('500.00');
        $pago = $this->porCaja('500.00', [[$compra, '500.00']]);
        $sesion->update(['status' => 'closed']);

        // Sesión cerrada: pide permiso de supervisor (regla de CashCorrectionService).
        $this->rechazoDeCaja(fn () => $this->servicio()->anular($pago, 'Duplicado', $this->user), 'sesión de caja cerrada', 403);
        $this->assertSame('vigente', $pago->fresh()->estado);
        $this->assertEstado($compra, 'pagado', '0.00');
    }

    public function test_el_movimiento_de_un_pago_no_se_corrige_desde_caja(): void
    {
        $this->cajaConFondo();
        $pago = $this->porCaja('100.00');
        $caja = app(CashMovementController::class);
        $edicion = new Request(['payment_method_id' => $this->efectivo->id, 'amount' => 50, 'concept_id' => 1, 'description' => 'x']);

        $this->rechazoDeCaja(fn () => $caja->update($edicion, (string) $pago->cash_movement_id), 'Solo se pueden editar/eliminar');
        $this->rechazoDeCaja(fn () => $caja->destroy((string) $pago->cash_movement_id), 'Solo se pueden editar/eliminar');
    }

    // ── La compra con pagos (CA14) y «Pagada ahora» (CA15) ──────────

    private function editarCompra(Compra $compra, string $total, array $cambios = []): Compra
    {
        $datos = $this->datosCompra($total, ['serie' => $compra->serie, 'numero' => $compra->numero] + $cambios);

        return app(CompraService::class)->editar(Request::create("/api/compras/{$compra->id}", 'POST', $datos), $compra->fresh());
    }

    public function test_no_se_anula_ni_se_cambia_una_compra_con_pagos(): void
    {
        $compra = $this->compra('1000.00');
        $pago = $this->porBanco('400.00', [[$compra, '400.00']]);
        $this->nota($compra, '100.00');
        $anular = fn () => app(CompraService::class)->anular($compra, 'Duplicada', $this->user->id);

        $this->rechazo($anular, 'Desaplica primero los pagos');

        $otro = $this->proveedor('T136 Otro SAC', '20136000002');
        $this->rechazo(fn () => $this->editarCompra($compra, '1000.00', ['proveedor_id' => $otro->id]), 'pagos');
        $this->rechazo(fn () => $this->editarCompra($compra, '1000.00', ['moneda' => 'USD', 'tipo_cambio' => '3.7']), 'pagos');
        $this->rechazo(fn () => $this->editarCompra($compra, '499.99'), 'lo pagado y sus notas de crédito (500.00)');
        $this->assertSame('500.00', $this->editarCompra($compra, '500.00')->total);

        $this->servicio()->desaplicar($pago->aplicaciones()->first(), 'Compra equivocada', $this->user);
        // Aún tiene su nota de crédito (regla de T-162): se anula primero.
        app(CompraService::class)->anular(Compra::where('compra_referencia_id', $compra->id)->sole(), 'x', $this->user->id);
        $this->assertTrue($anular()->estaAnulada());
    }

    public function test_un_pago_por_aprobar_tambien_frena_la_anulacion(): void
    {
        $this->cajaConFondo();
        $this->exigirAprobacionSobre('10.00');
        $compra = $this->compra('100.00');
        $this->porCaja('100.00', [[$compra, '100.00']]);

        $this->rechazo(fn () => app(CompraService::class)->anular($compra, 'x', $this->user->id), 'Desaplica primero');
    }

    private function pagadaAhora(array $pago, array $cambios = [], string $total = '300.00'): Compra
    {
        return $this->compra($total, $cambios + ['pago' => $pago]);
    }

    public function test_contado_pagada_ahora_desde_caja_o_banco(): void
    {
        $sesion = $this->cajaConFondo();

        $porCaja = $this->pagadaAhora(['origen' => 'caja', 'payment_method_id' => $this->efectivo->id]);
        $this->assertEstado($porCaja, 'pagado', '0.00');
        $this->assertSame(1700.0, $this->esperado($sesion));

        $porBanco = $this->pagadaAhora([
            'origen' => 'banco', 'cuenta_bancaria_id' => $this->cuenta->id, 'numero_operacion' => 'T-1', 'fecha' => '2026-03-14',
            // El monto y el proveedor los pone la compra: lo que se envíe no cuenta.
            'monto' => '1', 'proveedor_id' => 999999,
        ]);
        $this->assertEstado($porBanco, 'pagado', '0.00');
        $pago = $porBanco->pagos()->sole();
        $this->assertSame(['300.00', '2026-03-14', $this->proveedor->id], [$pago->monto, $pago->fecha->toDateString(), $pago->proveedor_id]);
    }

    public function test_si_falla_el_pago_no_queda_la_compra(): void
    {
        $compras = Compra::count();
        try {
            $this->pagadaAhora(['origen' => 'caja', 'payment_method_id' => $this->efectivo->id]);
            $this->fail('Sin sesión de caja no debía guardar.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('No hay una sesión de caja abierta', $e->getMessage());
        }
        $this->assertSame($compras, Compra::count());
        $this->assertSame(0, PagoProveedor::count());

        $this->rechazo(fn () => $this->pagadaAhora(['origen' => 'banco', 'fecha' => self::HOY]), 'Falta la cuenta bancaria');
        $this->rechazo(
            fn () => $this->pagadaAhora(['origen' => 'caja'], ['condicion' => 'credito', 'fecha_vencimiento' => '2026-04-01']),
            'al contado'
        );
        $this->assertSame($compras, Compra::count());
    }

    public function test_sin_compras_pagar_la_compra_queda_pendiente(): void
    {
        $this->user = $this->usuario(['compras.registrar']);

        $compra = $this->pagadaAhora(['origen' => 'caja', 'payment_method_id' => $this->efectivo->id]);

        $this->assertEstado($compra, 'pendiente', '300.00');
        $this->assertSame(0, PagoProveedor::count());
    }

    // ── API (CA17) ──────────────────────────────────────────────────

    private function api(): PagoProveedorController
    {
        return app(PagoProveedorController::class);
    }

    private function peticion(array $datos = [], string $metodo = 'GET'): Request
    {
        return Request::create('/api/compras/pagos', $metodo, $datos);
    }

    public function test_api_registrar_aplicar_listar_y_anular(): void
    {
        $compra = $this->compra('1000.00');
        $dolares = $this->compra('50.00', ['moneda' => 'USD', 'tipo_cambio' => '3.7']);

        $pendientes = $this->api()->comprasPendientes($this->peticion(['moneda' => 'PEN']), (string) $this->proveedor->id)->getData(true)['compras'];
        $this->assertSame([$compra->id], array_column($pendientes, 'id'));
        $this->assertSame(['1000.00', '1000.00'], [$pendientes[0]['saldo'], $pendientes[0]['maximo']]);

        $creado = $this->api()->store($this->peticion([
            'proveedor_id' => $this->proveedor->id, 'origen' => 'banco', 'moneda' => 'PEN', 'monto' => '700.00',
            'fecha' => '2026-03-10', 'cuenta_bancaria_id' => $this->cuenta->id, 'numero_operacion' => 'API-1',
        ], 'POST'))->getData(true)['pago'];
        $this->assertSame(['700.00', '0.00'], [$creado['a_cuenta'], $creado['aplicado']]);

        $aplicado = $this->api()->aplicar($this->peticion(['aplicaciones' => [['compra_id' => $compra->id, 'monto' => '400']]], 'POST'), (string) $creado['id'])->getData(true)['pago'];
        $this->assertSame(['300.00', '400.00'], [$aplicado['a_cuenta'], $aplicado['aplicado']]);

        $listar = fn (array $filtros) => array_column($this->api()->index($this->peticion($filtros))->getData(true)['pagos'], 'id');
        $this->assertSame([$creado['id']], $listar(['con_saldo' => 1, 'origen' => 'banco', 'estado' => 'vigente', 'proveedor_id' => $this->proveedor->id]));
        $this->assertSame([$creado['id']], $listar(['desde' => '2026-03-10', 'hasta' => '2026-03-10']));
        $this->assertSame([], $listar(['desde' => '2026-03-11']));
        $this->assertSame([], $listar(['origen' => 'caja']));

        $compras = app(CompraController::class);
        $fila = collect($compras->index(new Request)->getData(true)['compras'])->firstWhere('id', $compra->id);
        $this->assertSame(['parcial', '600.00'], [$fila['estado_pago'], $fila['saldo']]);
        $ficha = $compras->show((string) $compra->id)->getData(true)['compra'];
        $this->assertSame(['parcial', '600.00', '400.00'], [$ficha['estado_pago'], $ficha['saldo'], $ficha['aplicaciones_pago'][0]['monto']]);
        $this->assertSame($creado['id'], $ficha['aplicaciones_pago'][0]['pago']['id']);

        $this->api()->desaplicar($this->peticion(['motivo' => 'Otra compra'], 'POST'), (string) $ficha['aplicaciones_pago'][0]['id']);
        $anulado = $this->api()->anular($this->peticion(['motivo' => 'Error'], 'POST'), (string) $creado['id'])->getData(true)['pago'];
        $this->assertSame(['anulado', '0.00'], [$anulado['estado'], $anulado['a_cuenta']]);
        $this->assertSame([], $listar(['con_saldo' => 1]));
        $this->assertNotEmpty($dolares->id);

        $catalogos = $compras->catalogos(new Request, app(\App\Services\TipoCambio\TipoCambioSunatResolver::class))->getData(true);
        $this->assertContains($this->cuenta->id, array_column($catalogos['cuentas_bancarias'], 'id'));
        $this->assertContains($this->efectivo->id, array_column($catalogos['metodos_pago'], 'id'));
    }

    public function test_compras_pendientes_exige_modulo_y_permiso(): void
    {
        $ruta = Route::getRoutes()->match(Request::create('/api/proveedores/1/compras-pendientes', 'GET'));

        $this->assertContains('modulo:compras', $ruta->gatherMiddleware());
        $this->assertContains('permission:compras.pagar', $ruta->gatherMiddleware());
        $this->assertContains('tenant', $ruta->gatherMiddleware());
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
    // ── Revisión del 27-nov (/code-review) ───────────────────────────

    public function test_la_moneda_de_una_cuenta_con_pagos_no_cambia(): void
    {
        $this->porBanco('10.00');
        $controller = app(\App\Http\Controllers\CuentaBancariaController::class);
        $datos = fn (?string $moneda) => new Request([
            'banco' => 'BCP', 'titular' => 'Empresa', 'numero_cuenta' => $this->cuenta->numero_cuenta, 'moneda' => $moneda,
        ]);

        foreach (['USD', null] as $moneda) {
            $respuesta = $controller->update($datos($moneda), (string) $this->cuenta->id);
            $this->assertSame(422, $respuesta->getStatusCode(), (string) $moneda);
            $this->assertStringContainsString('ya tiene pagos', $respuesta->getData(true)['message']);
        }
        $this->assertSame('PEN', $this->cuenta->fresh()->moneda);

        // Lo demás se sigue editando, y una cuenta sin pagos cambia de moneda.
        $this->assertSame(200, $controller->update($datos('PEN'), (string) $this->cuenta->id)->getStatusCode());
        $sinPagos = $this->cuenta('PEN');
        $this->assertSame(200, $controller->update($datos('USD'), (string) $sinPagos->id)->getStatusCode());
        $this->assertSame('USD', $sinPagos->fresh()->moneda);
    }

    public function test_no_paga_desde_una_sesion_que_se_cerro_antes_del_bloqueo(): void
    {
        $this->cajaConFondo('500.00');
        // La sesión se cierra justo después de leerla y antes de bloquearla.
        $this->app->instance(\App\Services\Cash\EgresoCajaService::class, new class(app(ExpectedCashCalculator::class)) extends \App\Services\Cash\EgresoCajaService
        {
            public function sesionAbierta(User $usuario): CashSession
            {
                $sesion = parent::sesionAbierta($usuario);
                CashSession::whereKey($sesion->id)->update(['status' => 'closed']);

                return $sesion;
            }
        });
        $antes = [PagoProveedor::count(), CashMovement::count()];

        $this->rechazo(fn () => $this->porCaja('50.00'), 'se acaba de cerrar');
        $this->assertSame($antes, [PagoProveedor::count(), CashMovement::count()]);
    }

    public function test_numero_de_operacion_cero_vale_y_una_lista_se_rechaza(): void
    {
        $this->assertSame('0', $this->porBanco('10.00', [], ['numero_operacion' => '0'])->numero_operacion);
        $this->rechazo(fn () => $this->porBanco('10.00', [], ['numero_operacion' => ['x']]), 'número de operación');
    }
}
