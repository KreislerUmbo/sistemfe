<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Company;
use App\Models\Compras\Compra;
use App\Models\Compras\PagoProveedor;
use App\Models\Compras\ReembolsoProveedor;
use App\Models\CuentaBancaria;
use App\Models\Proveedor;
use App\Models\TipoCambio\TipoCambioSunat;
use App\Models\User;
use App\Services\Compras\CompraException;
use App\Services\Compras\CompraService;
use App\Services\Compras\PagoProveedorService;
use App\Services\Compras\ReembolsoProveedorService;
use App\Services\ExpectedCashCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Creditos\CreditosTestCase;

// T-140 — base de sus tests, contra Postgres real. «Hoy» es 2026-03-15 en Perú.
abstract class ComprasT140TestCase extends CreditosTestCase
{
    protected const HOY = '2026-03-15';

    protected User $user;

    protected Proveedor $proveedor;

    protected CuentaBancaria $cuenta;

    protected CuentaBancaria $cuentaUsd;

    private int $numero = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoy(self::HOY);
        // tipo_cambio_sunat es central (otra conexión): su propia transacción, revertida al final.
        DB::connection('central')->beginTransaction();
        TipoCambioSunat::where('fecha', '<=', self::HOY)->delete();

        $this->user = $this->usuario(['compras.ver', 'compras.registrar', 'compras.anular', 'compras.pagar', 'cash.approve_expenses']);
        $this->proveedor = $this->proveedor('T140 Mayorista SAC', '20140000001');
        $this->cuenta = $this->cuenta('PEN');
        $this->cuentaUsd = $this->cuenta('USD');
        // La base de pruebas puede traer una empresa que exige aprobación.
        Company::query()->update(['require_expense_approval' => false]);
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    protected function ventaSunat(string $venta, string $fecha = self::HOY): void
    {
        TipoCambioSunat::create(['fecha' => $fecha, 'compra' => $venta, 'venta' => $venta, 'fuente' => 'test', 'consultado_en' => now()]);
    }

    protected function proveedor(string $nombre, string $ruc): Proveedor
    {
        return Proveedor::create(['razon_social' => $nombre, 'tipo_documento' => 'RUC', 'numero_documento' => $ruc]);
    }

    protected function cuenta(?string $moneda): CuentaBancaria
    {
        return CuentaBancaria::create(['banco' => 'BCP', 'titular' => 'T140', 'numero_cuenta' => (string) random_int(1, 999999), 'moneda' => $moneda]);
    }

    protected function datosCompra(string $total, array $cambios = []): array
    {
        return array_merge([
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante_codigo' => '01',
            'serie' => 'F140',
            'numero' => (string) ++$this->numero,
            'fecha_emision' => self::HOY,
            'moneda' => 'PEN',
            'base_gravada' => '0', 'igv' => '0', 'exonerado' => $total, 'inafecto' => '0', 'otros' => '0',
            'total' => $total,
            'condicion' => 'contado',
        ], $cambios);
    }

    protected function compra(string $total = '1000.00', array $cambios = []): Compra
    {
        return app(CompraService::class)
            ->registrar(Request::create('/api/compras', 'POST', $this->datosCompra($total, $cambios)), $this->user->id);
    }

    protected function compraUsd(string $total = '1000.00', string $tc = '3.7000'): Compra
    {
        return $this->compra($total, ['moneda' => 'USD', 'tipo_cambio' => $tc]);
    }

    protected function editarCompra(Compra $compra, string $total, array $cambios = []): Compra
    {
        $datos = $this->datosCompra($total, $cambios + [
            'serie' => $compra->serie, 'numero' => $compra->numero,
            'tipo_comprobante_codigo' => $compra->tipo_comprobante_codigo,
            'compra_referencia_id' => $compra->compra_referencia_id,
        ]);

        return app(CompraService::class)->editar(Request::create("/api/compras/{$compra->id}", 'POST', $datos), $compra->fresh());
    }

    protected function anularCompra(Compra $compra): Compra
    {
        return app(CompraService::class)->anular($compra, 'Prueba', $this->user->id);
    }

    protected function nota(Compra $original, string $total): Compra
    {
        return $this->compra($total, [
            'tipo_comprobante_codigo' => '07',
            'compra_referencia_id' => $original->id,
            'proveedor_id' => $original->proveedor_id,
            'moneda' => $original->moneda,
            'tipo_cambio' => $original->tipo_cambio,
        ]);
    }

    protected function servicio(): PagoProveedorService
    {
        return app(PagoProveedorService::class);
    }

    protected function reembolsos(): ReembolsoProveedorService
    {
        return app(ReembolsoProveedorService::class);
    }

    /** @param array<int, array{0: Compra, 1: string, 2?: string}> $aplicar [compra, monto de la compra, T.C.] */
    protected function filas(array $aplicar): array
    {
        return array_map(fn (array $a) => ['compra_id' => $a[0]->id, 'monto' => $a[1]]
            + (isset($a[2]) ? ['tipo_cambio' => $a[2]] : []), $aplicar);
    }

    protected function porBanco(string $monto, array $aplicar = [], array $cambios = []): PagoProveedor
    {
        $moneda = $cambios['moneda'] ?? 'PEN';

        return $this->servicio()->registrar(array_merge([
            'proveedor_id' => $this->proveedor->id,
            'origen' => 'banco',
            'moneda' => $moneda,
            'monto' => $monto,
            'fecha' => self::HOY,
            'cuenta_bancaria_id' => $moneda === 'USD' ? $this->cuentaUsd->id : $this->cuenta->id,
            'numero_operacion' => 'OP'.++$this->numero,
            'aplicaciones' => $this->filas($aplicar),
        ], $cambios), $this->user);
    }

    protected function porCaja(string $monto, array $aplicar = [], array $cambios = []): PagoProveedor
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

    protected function aplicar(PagoProveedor $pago, Compra $compra, string $monto, ?string $tc = null): PagoProveedor
    {
        return $this->servicio()->aplicar($pago, $this->filas([$tc === null ? [$compra, $monto] : [$compra, $monto, $tc]]), $this->user);
    }

    /** Reembolso por banco del saldo a favor de una compra o de lo «a cuenta» de un pago. */
    protected function reembolsar(Compra|PagoProveedor $destino, string $monto, array $cambios = []): ReembolsoProveedor
    {
        $moneda = $cambios['moneda'] ?? $destino->moneda;

        return $this->reembolsos()->registrar(array_merge([
            'proveedor_id' => $destino->proveedor_id,
            $destino instanceof Compra ? 'compra_id' : 'pago_proveedor_id' => $destino->id,
            'moneda' => $moneda,
            'monto' => $monto,
            'tipo_cambio' => $moneda === 'USD' ? '3.7000' : null,
            'origen' => 'banco',
            'fecha' => self::HOY,
            'cuenta_bancaria_id' => $moneda === 'USD' ? $this->cuentaUsd->id : $this->cuenta->id,
            'numero_operacion' => 'RE'.++$this->numero,
        ], $cambios), $this->user);
    }

    protected function reembolsarEnCaja(Compra|PagoProveedor $destino, string $monto): ReembolsoProveedor
    {
        return $this->reembolsar($destino, $monto, ['origen' => 'caja', 'payment_method_id' => $this->efectivo->id]);
    }

    protected function cajaConFondo(string $fondo = '2000.00', string $moneda = 'PEN'): CashSession
    {
        $sesion = CashSession::where('opened_by', $this->user->id)->where('status', 'open')->first() ?? $this->abrirCaja($this->user);
        CashMovement::create([
            'cash_session_id' => $sesion->id, 'type' => 'opening_fund', 'payment_method_id' => $this->efectivo->id,
            'direction' => 'in', 'amount' => $fondo, 'currency' => $moneda, 'status' => 'confirmed', 'created_by' => $this->user->id,
        ]);

        return $sesion;
    }

    protected function esperado(CashSession $sesion): float
    {
        return app(ExpectedCashCalculator::class)->compute($sesion);
    }

    protected function exigirAprobacionSobre(string $monto): void
    {
        $empresa = Company::first() ?? Company::create(['razon_social' => 'Empresa T140 SAC', 'razon_social_comercial' => 'Empresa T140', 'n_document' => '20140999999']);
        $empresa->update(['require_expense_approval' => true, 'max_expense_without_approval' => $monto]);
    }

    protected function assertEstado(Compra $compra, ?string $estado, ?string $saldo): void
    {
        $real = $this->servicio()->estados(collect([$compra->fresh()]))[$compra->id];
        $this->assertSame([$estado, $saldo], [$real['estado_pago'], $real['saldo']]);
    }

    /** El rechazo no deja nada guardado. */
    protected function rechazo(callable $accion, string $texto, int $status = 422): CompraException|HttpException
    {
        $contar = fn () => array_map(
            fn (string $tabla) => DB::table($tabla)->count(),
            ['pagos_proveedor', 'aplicaciones_pago_proveedor', 'reembolsos_proveedor', 'cash_movements']
        );
        $antes = $contar();
        try {
            $accion();
        } catch (CompraException|HttpException $e) {
            $real = $e instanceof CompraException ? $e->status : $e->getStatusCode();
            $this->assertSame($status, $real, $e->getMessage());
            $this->assertStringContainsString($texto, $e->getMessage());
            $this->assertSame($antes, $contar(), 'no debe guardarse nada');

            return $e;
        }
        $this->fail("Se esperaba el rechazo: {$texto}");
    }
}
