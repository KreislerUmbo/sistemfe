<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Http\Controllers\Compras\CompraController;
use App\Http\Controllers\Compras\CuentasPorPagarController;
use App\Http\Controllers\Compras\PagoProveedorController;
use App\Models\Cash\CashMovement;
use App\Models\Company;
use App\Models\Compras\Compra;
use App\Models\Compras\PagoProveedor;
use App\Models\CuentaBancaria;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Compras\CompraService;
use App\Services\Compras\PagoProveedorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Creditos\CreditosTestCase;

// T-137 — cuentas por pagar, contra Postgres real. «Hoy» es 2026-03-15 en Perú.
class CuentasPorPagarTest extends CreditosTestCase
{
    private const HOY = '2026-03-15';

    private const RUC = '20137000001';

    private User $user;

    private Proveedor $proveedor;

    private CuentaBancaria $cuenta;

    private int $numero = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoy(self::HOY);

        $this->user = $this->usuario(['compras.ver', 'compras.registrar', 'compras.anular', 'compras.pagar']);
        $this->proveedor = $this->proveedor('T137 Distribuidora SAC', self::RUC);
        $this->cuenta = CuentaBancaria::create(['banco' => 'BCP', 'titular' => 'T137', 'numero_cuenta' => (string) random_int(1, 999999), 'moneda' => 'PEN']);
    }

    private function proveedor(string $nombre, ?string $documento, array $cambios = []): Proveedor
    {
        return Proveedor::create(['razon_social' => $nombre, 'tipo_documento' => 'RUC', 'numero_documento' => $documento] + $cambios);
    }

    private function compra(string $total = '1000.00', array $cambios = []): Compra
    {
        $datos = array_merge([
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante_codigo' => '01',
            'serie' => 'F137',
            'numero' => (string) ++$this->numero,
            'fecha_emision' => self::HOY,
            'moneda' => 'PEN',
            'base_gravada' => '0', 'igv' => '0', 'exonerado' => $total, 'inafecto' => '0', 'otros' => '0',
            'total' => $total,
            'condicion' => 'contado',
        ], $cambios);

        return app(CompraService::class)->registrar(Request::create('/api/compras', 'POST', $datos), $this->user->id);
    }

    /** Compra a crédito que vence el día dado. */
    private function credito(string $total, string $vence): Compra
    {
        return $this->compra($total, ['condicion' => 'credito', 'fecha_emision' => '2025-11-01', 'fecha_vencimiento' => $vence]);
    }

    private function nota(Compra $original, string $total): Compra
    {
        return $this->compra($total, [
            'tipo_comprobante_codigo' => '07',
            'compra_referencia_id' => $original->id,
            'proveedor_id' => $original->proveedor_id,
        ]);
    }

    /** @param array<int, array{0: Compra, 1: string}> $aplicar */
    private function porBanco(string $monto, array $aplicar = [], array $cambios = []): PagoProveedor
    {
        return app(PagoProveedorService::class)->registrar(array_merge([
            'proveedor_id' => $this->proveedor->id,
            'origen' => 'banco',
            'moneda' => 'PEN',
            'monto' => $monto,
            'fecha' => self::HOY,
            'cuenta_bancaria_id' => $this->cuenta->id,
            'numero_operacion' => 'OP'.++$this->numero,
            'aplicaciones' => array_map(fn (array $a) => ['compra_id' => $a[0]->id, 'monto' => $a[1]], $aplicar),
        ], $cambios), $this->user);
    }

    private function pedido(array $filtros = []): Request
    {
        return Request::create('/api/compras/cuentas-por-pagar', 'GET', $filtros);
    }

    private function listado(array $filtros = []): array
    {
        return app(CuentasPorPagarController::class)->index($this->pedido($filtros))->getData(true);
    }

    private function detalle(string $clave): array
    {
        return app(CuentasPorPagarController::class)->detalle($this->pedido(['clave' => $clave]))->getData(true)['grupo'];
    }

    private function grupo(string $clave, array $filtros = []): array
    {
        return collect($this->listado($filtros)['grupos'])->firstWhere('clave', $clave) ?? $this->fail("No está el grupo {$clave}");
    }

    /** Las cifras en una moneda de un grupo, de una ficha o de los totales. */
    private function en(array $monedas, string $moneda = 'PEN'): array
    {
        return collect($monedas)->firstWhere('moneda', $moneda) ?? $this->fail("Sin cifras en {$moneda}");
    }

    /** @param array<string, string> $esperado solo se comparan sus claves */
    private function assertCifras(array $esperado, array $real): void
    {
        foreach ($esperado as $clave => $valor) {
            $this->assertSame($valor, $real[$clave], $clave);
        }
    }

    // ── CA1 ──────────────────────────────────────────────────────────

    public function test_agrupa_por_ruc_con_sus_fichas(): void
    {
        $hotel = $this->proveedor('T137 Distribuidora SAC', '2013 7000001 ', ['nombre_comercial' => 'Hotel Selva']);
        $suelto = $this->proveedor('T137 Suelto', '20137000009');
        $this->compra('100.00');
        $this->compra('50.00', ['proveedor_id' => $hotel->id]);
        $this->compra('30.00', ['proveedor_id' => $suelto->id]);
        // Manda el documento actual de la ficha, no el que quedó en la compra.
        $suelto->update(['numero_documento' => null]);

        $grupos = collect($this->listado()['grupos'])->keyBy('clave');

        $this->assertEqualsCanonicalizing([self::RUC, "id:{$suelto->id}"], $grupos->keys()->all());
        $ruc = $grupos[self::RUC];
        $this->assertSame(self::RUC, $ruc['documento']);
        $this->assertEqualsCanonicalizing([$this->proveedor->id, $hotel->id], array_column($ruc['fichas'], 'proveedor_id'));
        $this->assertSame('150.00', $this->en($ruc['monedas'])['pendiente']);
        $this->assertNull($grupos["id:{$suelto->id}"]['documento']);
        $this->assertSame('30.00', $this->en($grupos["id:{$suelto->id}"]['monedas'])['pendiente']);

        // Sin distinguir mayúsculas.
        $uno = $this->proveedor('T137 Exterior', 'ab-123');
        $dos = $this->proveedor('T137 Exterior', 'AB -123');
        $this->porBanco('10.00', [], ['proveedor_id' => $uno->id]);
        $this->porBanco('5.00', [], ['proveedor_id' => $dos->id]);
        $this->assertSame('15.00', $this->en($this->grupo('AB-123')['monedas'])['a_cuenta']);
    }

    // ── CA2, CA3, CA4 ────────────────────────────────────────────────

    public function test_pendiente_vencido_y_por_vencer(): void
    {
        $this->credito('100.00', '2026-03-14');
        $this->credito('200.00', '2026-03-20');
        $this->credito('400.00', '2026-03-25');
        $this->compra('800.00', ['fecha_emision' => '2026-03-12']);

        $datos = $this->listado(['dias' => 7]);

        $esperado = ['pendiente' => '1500.00', 'vencido' => '900.00', 'por_vencer' => '200.00'];
        $this->assertCifras($esperado, $this->en($datos['grupos'][0]['monedas']));
        $this->assertCifras($esperado, $this->en($datos['totales']));
        // El último día del plazo entra.
        $this->assertSame('600.00', $this->en($this->listado(['dias' => 10])['totales'])['por_vencer']);
    }

    public function test_vencido_segun_la_fecha_de_peru(): void
    {
        $this->credito('100.00', self::HOY);
        // 20:00 del 15 en Lima ya es 16 en UTC.
        $this->hoy(self::HOY, '20:00:00');

        $this->assertCifras(
            ['pendiente' => '100.00', 'vencido' => '0.00', 'por_vencer' => '100.00'],
            $this->en($this->grupo(self::RUC)['monedas'])
        );
    }

    public function test_antiguedad_de_lo_vencido(): void
    {
        $hace = [1 => '1.00', 30 => '2.00', 31 => '4.00', 60 => '8.00', 61 => '16.00', 90 => '32.00', 91 => '64.00'];
        foreach ($hace as $dias => $total) {
            $this->credito($total, date('Y-m-d', strtotime(self::HOY." -{$dias} days")));
        }

        $cifras = $this->en($this->grupo(self::RUC)['monedas']);

        $this->assertSame(['d1_30' => '3.00', 'd31_60' => '12.00', 'd61_90' => '48.00', 'mas_90' => '64.00'], $cifras['antiguedad']);
        $this->assertSame('127.00', $cifras['vencido']);
        $atrasos = array_column($this->detalle(self::RUC)['fichas'][0]['compras'], 'dias_atraso');
        $this->assertEqualsCanonicalizing(array_keys($hace), $atrasos);
    }

    // ── CA5, CA6, CA7 ────────────────────────────────────────────────

    public function test_lo_por_aprobar_no_baja_la_deuda(): void
    {
        $compra = $this->compra('1000.00');
        $sesion = $this->abrirCaja($this->user);
        CashMovement::create([
            'cash_session_id' => $sesion->id, 'type' => 'opening_fund', 'payment_method_id' => $this->efectivo->id,
            'direction' => 'in', 'amount' => '2000.00', 'currency' => 'PEN', 'status' => 'confirmed', 'created_by' => $this->user->id,
        ]);
        $empresa = Company::first() ?? Company::create(['razon_social' => 'Empresa T137 SAC', 'razon_social_comercial' => 'Empresa T137', 'n_document' => '20137999999']);
        $empresa->update(['require_expense_approval' => true, 'max_expense_without_approval' => '100.00']);

        $antes = app(CompraController::class)->show((string) $compra->id)->getData(true)['compra'];
        $pago = app(PagoProveedorService::class)->registrar([
            'proveedor_id' => $this->proveedor->id, 'origen' => 'caja', 'moneda' => 'PEN', 'monto' => '600.00',
            'payment_method_id' => $this->efectivo->id, 'aplicaciones' => [['compra_id' => $compra->id, 'monto' => '400.00']],
        ], $this->user);
        $this->assertSame(PagoProveedor::PENDIENTE, $pago->estado);

        // Por aprobar es el pago entero (aplicado o no); a cuenta, todavía nada.
        $this->assertCifras(
            ['pendiente' => '1000.00', 'por_aprobar' => '600.00', 'a_cuenta' => '0.00', 'neto' => '1000.00'],
            $this->en($this->grupo(self::RUC)['monedas'])
        );

        $despues = app(CompraController::class)->show((string) $compra->id)->getData(true)['compra'];
        $this->assertSame([$antes['saldo'], $antes['estado_pago']], [$despues['saldo'], $despues['estado_pago']]);

        $ficha = $this->detalle(self::RUC)['fichas'][0];
        $this->assertCifras(['saldo' => '1000.00', 'por_aprobar' => '400.00'], $ficha['compras'][0]);
        $this->assertCifras(['estado' => PagoProveedor::PENDIENTE, 'por_aprobar' => '600.00', 'a_cuenta' => '0.00'], $ficha['pagos'][0]);
    }

    public function test_a_cuenta_y_neto(): void
    {
        $pagada = $this->compra('1000.00');
        $this->compra('800.00');
        $this->porBanco('1500.00', [[$pagada, '1000.00']]);
        $anulado = $this->porBanco('300.00');
        app(PagoProveedorService::class)->anular($anulado, 'No salió', $this->user);

        $this->assertCifras(
            ['pendiente' => '800.00', 'a_cuenta' => '500.00', 'por_aprobar' => '0.00', 'neto' => '300.00'],
            $this->en($this->grupo(self::RUC)['monedas'])
        );
        $pagos = $this->detalle(self::RUC)['fichas'][0]['pagos'];
        $this->assertCount(1, $pagos);
        $this->assertSame('500.00', $pagos[0]['a_cuenta']);
    }

    public function test_saldo_a_favor_de_una_nota(): void
    {
        $compra = $this->compra('1000.00');
        $this->porBanco('1000.00', [[$compra, '1000.00']]);
        $this->nota($compra, '200.00');

        $this->assertCifras(
            ['pendiente' => '0.00', 'vencido' => '0.00', 'a_favor' => '200.00', 'neto' => '-200.00'],
            $this->en($this->grupo(self::RUC)['monedas'])
        );
        $this->assertSame('-200.00', $this->detalle(self::RUC)['fichas'][0]['compras'][0]['saldo']);
    }

    // ── CA8, CA9, CA10 ───────────────────────────────────────────────

    public function test_monedas_separadas(): void
    {
        $this->compra('100.00', ['moneda' => 'USD', 'tipo_cambio' => '3.7']);
        $this->compra('500.00');

        $datos = $this->listado();

        foreach ([$datos['grupos'][0]['monedas'], $datos['totales'], $datos['grupos'][0]['fichas'][0]['monedas']] as $monedas) {
            $this->assertSame(['PEN', 'USD'], array_column($monedas, 'moneda'));
            $this->assertSame(['500.00', '100.00'], array_column($monedas, 'pendiente'));
            $this->assertSame(['500.00', '100.00'], array_column($monedas, 'neto'));
        }
    }

    public function test_que_entra_al_listado(): void
    {
        // Nada pendiente: una anulada y una pagada entera.
        $cero = $this->proveedor('T137 En cero', '20137000002');
        app(CompraService::class)->anular($this->compra('70.00', ['proveedor_id' => $cero->id]), 'Duplicada', $this->user->id);
        $pagada = $this->compra('90.00', ['proveedor_id' => $cero->id]);
        $this->porBanco('90.00', [[$pagada, '90.00']], ['proveedor_id' => $cero->id]);

        $soloPago = $this->proveedor('T137 Solo adelanto', '20137000003');
        $this->porBanco('40.00', [], ['proveedor_id' => $soloPago->id]);

        $deuda = $this->compra('100.00');
        $this->nota($deuda, '25.00');
        app(CompraService::class)->anular($this->compra('999.00'), 'Duplicada', $this->user->id);

        $grupos = collect($this->listado()['grupos'])->keyBy('clave');

        $this->assertEqualsCanonicalizing([self::RUC, '20137000003'], $grupos->keys()->all());
        $this->assertSame('75.00', $this->en($grupos[self::RUC]['monedas'])['pendiente']);
        $this->assertCifras(['pendiente' => '0.00', 'a_cuenta' => '40.00', 'neto' => '-40.00'], $this->en($grupos['20137000003']['monedas']));
        $this->assertCount(1, $this->detalle(self::RUC)['fichas'][0]['compras']);

        foreach (['20137000002', 'no-existe'] as $clave) {
            try {
                $this->detalle($clave);
                $this->fail('Debió responder 404');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }

    public function test_detalle_usa_el_mismo_saldo(): void
    {
        $parcial = $this->compra('1000.00');
        $this->porBanco('350.50', [[$parcial, '350.50']]);
        $conNota = $this->credito('500.00', '2026-04-01');
        $this->nota($conNota, '120.00');

        $compras = $this->detalle(self::RUC)['fichas'][0]['compras'];
        $pendientes = app(PagoProveedorController::class)
            ->comprasPendientes(Request::create('/x', 'GET'), (string) $this->proveedor->id)->getData(true)['compras'];
        $pendientes = array_column($pendientes, null, 'id');

        $this->assertCount(2, $compras);
        foreach ($compras as $compra) {
            $ficha = app(CompraController::class)->show((string) $compra['id'])->getData(true)['compra'];
            $this->assertSame([$ficha['saldo'], $ficha['estado_pago']], [$compra['saldo'], $compra['estado_pago']]);
            $this->assertSame($pendientes[$compra['id']]['saldo'], $compra['saldo']);
        }
    }

    // ── CA11, CA12, CA13 ─────────────────────────────────────────────

    public function test_sin_n_mas_1(): void
    {
        $proveedores = [$this->proveedor];
        for ($i = 2; $i <= 10; $i++) {
            $proveedores[] = $this->proveedor("T137 Proveedor {$i}", sprintf('2013700%04d', 100 + $i));
        }
        $cargar = function (int $compras, int $entre) use ($proveedores) {
            for ($i = 0; $i < $compras; $i++) {
                $dueno = ['proveedor_id' => $proveedores[$i % $entre]->id];
                $compra = $this->compra('100.00', $dueno);
                $this->porBanco('20.00', [[$compra, '10.00']], $dueno);
            }
        };
        $consultas = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->listado();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $cargar(3, 3);
        $con3 = $consultas();
        $cargar(27, 10);

        $this->assertSame(10, $this->listado()['total']);
        $this->assertGreaterThan(0, $con3);
        $this->assertSame($con3, $consultas());
    }

    public function test_filtros(): void
    {
        $zeta = $this->proveedor('T137 Zeta EIRL', '20137000004');
        $this->proveedor('T137 Zeta EIRL', '20137000004', ['nombre_comercial' => 'Posada 100%']);
        $this->credito('100.00', '2026-04-01');
        $this->compra('60.00', ['proveedor_id' => $zeta->id, 'fecha_emision' => '2026-03-01']);

        $claves = fn (array $filtros) => array_column($this->listado($filtros)['grupos'], 'clave');

        // El que tiene algo vencido va primero, aunque su nombre vaya después.
        $this->assertSame(['20137000004', self::RUC], $claves([]));
        $this->assertSame([self::RUC], $claves(['search' => '137000001']));
        // Por el nombre comercial de una ficha del mismo RUC, aunque esa ficha no deba nada.
        $this->assertSame(['20137000004'], $claves(['search' => 'posada']));
        $this->assertSame(['20137000004'], $claves(['search' => '100%']));
        $this->assertSame([], $claves(['search' => '%%']));
        $this->assertSame(['20137000004'], $claves(['solo_vencido' => '1']));
        $this->assertSame('60.00', $this->en($this->listado(['solo_vencido' => 'true'])['totales'])['pendiente']);

        // La página no cambia el total ni los totales.
        $segunda = $this->listado(['per_page' => 1, 'page' => 2]);
        $this->assertSame([2, [self::RUC]], [$segunda['total'], array_column($segunda['grupos'], 'clave')]);
        $this->assertSame('160.00', $this->en($segunda['totales'])['pendiente']);

        foreach ([['dias' => 0], ['dias' => 400], ['per_page' => 101]] as $malo) {
            try {
                $this->listado($malo);
                $this->fail('Debió responder 422: '.json_encode($malo));
            } catch (ValidationException $e) {
                $this->assertSame(422, $e->status);
            }
        }
    }

    // El 403 con el módulo apagado lo da EnsureModuloActivo (ModulosTenantTest): acá se comprueba
    // que las rutas lo lleven.
    public function test_permisos_y_modulo(): void
    {
        foreach (['', '/detalle', '/excel'] as $sufijo) {
            $ruta = Route::getRoutes()->match(Request::create("/api/compras/cuentas-por-pagar{$sufijo}", 'GET'));
            $this->assertSame("api/compras/cuentas-por-pagar{$sufijo}", $ruta->uri());
            foreach (['tenant', 'modulo:compras', 'permission:compras.ver'] as $middleware) {
                $this->assertContains($middleware, $ruta->gatherMiddleware(), $sufijo);
            }
        }

        $this->usuario(['compras.registrar']);
        $this->expectException(UnauthorizedException::class);
        app(PermissionMiddleware::class)->handle(new Request, fn () => response('ok'), 'compras.ver', 'api');
    }

    // ── CA14 ─────────────────────────────────────────────────────────

    public function test_excel(): void
    {
        $hotel = $this->proveedor('T137 Distribuidora SAC', self::RUC, ['nombre_comercial' => 'Hotel Selva']);
        $raro = $this->proveedor('=1+1', '00137000');
        $this->credito('100.00', '2026-03-14');
        $this->compra('50.00', ['proveedor_id' => $hotel->id, 'moneda' => 'USD', 'tipo_cambio' => '3.7']);
        $this->porBanco('30.00');
        $this->porBanco('10.00', [], ['proveedor_id' => $raro->id]);

        $respuesta = app(CuentasPorPagarController::class)->excel($this->pedido(['dias' => 15]));
        $hoja = IOFactory::load($respuesta->getFile()->getPathname())->getActiveSheet();
        $filas = $hoja->toArray(null, false, false);

        $this->assertStringContainsString('cuentas-por-pagar-2026-03-15.xlsx', $respuesta->headers->get('content-disposition'));
        $this->assertSame([
            'RUC / documento', 'Razón social', 'Fichas', 'Moneda', 'Pendiente', 'Vencido', 'Vence en 15 días',
            '1-30', '31-60', '61-90', '+90', 'Por aprobar', 'A cuenta', 'A favor', 'Neto',
        ], $filas[0]);

        $json = $this->listado(['dias' => 15]);
        $cifras = fn (array $m) => array_map('floatval', [
            $m['pendiente'], $m['vencido'], $m['por_vencer'], ...array_values($m['antiguedad']),
            $m['por_aprobar'], $m['a_cuenta'], $m['a_favor'], $m['neto'],
        ]);
        $esperadas = [];
        foreach ($json['grupos'] as $grupo) {
            foreach ($grupo['monedas'] as $moneda) {
                $esperadas[] = [(string) $grupo['documento'], $grupo['razon_social'], $moneda['moneda'], $cifras($moneda)];
            }
        }
        foreach ($json['totales'] as $total) {
            $esperadas[] = ['', 'Total', $total['moneda'], $cifras($total)];
        }
        $reales = array_map(
            fn (array $f) => [(string) $f[0], $f[1], $f[3], array_map('floatval', array_slice($f, 4))],
            array_slice($filas, 1)
        );
        $this->assertSame($esperadas, $reales);

        // El RUC (con algo vencido) va primero, una fila por moneda, con sus dos fichas.
        $this->assertSame(['PEN', 'USD', 'PEN', 'PEN', 'USD'], array_column(array_slice($filas, 1), 3));
        $this->assertSame('T137 Distribuidora SAC / Hotel Selva', $filas[1][2]);
        // Un nombre que empieza con «=» no es una fórmula y el documento no pierde sus ceros.
        $this->assertSame(['00137000', '=1+1'], [$filas[3][0], $filas[3][1]]);
        $this->assertSame('s', $hoja->getCell('B4')->getDataType());
        $this->assertSame('#,##0.00', $hoja->getStyle('E2')->getNumberFormat()->getFormatCode());
    }
}
