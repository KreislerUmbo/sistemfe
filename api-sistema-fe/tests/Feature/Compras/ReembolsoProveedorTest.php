<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Http\Controllers\Cash\CashMovementController;
use App\Http\Controllers\Compras\CompraController;
use App\Http\Controllers\Compras\CuentasPorPagarController;
use App\Http\Controllers\Compras\PagoProveedorController;
use App\Models\Cash\CashMovement;
use App\Models\Compras\Compra;
use App\Models\Compras\PagoProveedor;
use App\Models\Compras\ReembolsoProveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;

// T-140, parte B — reembolsos del proveedor.
class ReembolsoProveedorTest extends ComprasT140TestCase
{
    /** Compra pagada entera y con una nota posterior: queda a favor lo de la nota. */
    private function aFavor(string $total = '1000.00', string $nota = '300.00'): array
    {
        $compra = $this->compra($total);
        $pago = $this->porBanco($total, [[$compra, $total]]);

        return [$compra, $pago, $this->nota($compra, $nota)];
    }

    /** El grupo del proveedor en cuentas por pagar, o [] si ya no aparece. */
    private function cuentasPorPagar(): array
    {
        $grupos = app(CuentasPorPagarController::class)
            ->index(Request::create('/api/compras/cuentas-por-pagar', 'GET'))->getData(true)['grupos'];

        return collect($grupos)->firstWhere('clave', '20140000001') ?? [];
    }

    private function detalle(): array
    {
        return app(CuentasPorPagarController::class)
            ->detalle(Request::create('/api/compras/cuentas-por-pagar/detalle', 'GET', ['clave' => '20140000001']))
            ->getData(true)['grupo'];
    }

    private function fichaPago(int $pagoId): array
    {
        return app(PagoProveedorController::class)->show((string) $pagoId)->getData(true)['pago'];
    }

    private function listadoDePagos(array $filtros = []): array
    {
        return app(PagoProveedorController::class)
            ->index(Request::create('/api/compras/pagos', 'GET', $filtros))->getData(true)['pagos'];
    }

    public function test_reembolso_de_nota_de_credito_por_banco(): void // CA10
    {
        [$compra] = $this->aFavor();
        $this->assertEstado($compra, 'pagado', '-300.00');
        $this->assertSame('300.00', $this->cuentasPorPagar()['monedas'][0]['a_favor']);

        $reembolso = $this->reembolsar($compra, '300.00');

        $this->assertSame(['vigente', 'banco', '300.00', null], [$reembolso->estado, $reembolso->origen, $reembolso->monto, $reembolso->cash_movement_id]);
        $this->assertEstado($compra, 'pagado', '0.00');
        $this->assertSame([], $this->cuentasPorPagar());
        $ficha = app(CompraController::class)->show((string) $compra->id)->getData(true)['compra'];
        $this->assertSame('300.00', $ficha['reembolsado']);
        $this->assertSame($reembolso->id, $ficha['reembolsos'][0]['id']);
    }

    public function test_reembolso_por_caja(): void // CA11
    {
        [$compra] = $this->aFavor();
        $this->rechazo(fn () => $this->reembolsarEnCaja($compra, '300.00'), 'No hay una sesión de caja abierta');

        $sesion = $this->cajaConFondo('100.00');
        // La fecha enviada no cuenta: en Caja es hoy en Perú, también de noche.
        $this->hoy(self::HOY, '23:30:00');
        $reembolso = $this->reembolsarEnCaja($compra, '300.00');

        $this->assertSame(self::HOY, $reembolso->fecha->toDateString());
        $movimiento = $reembolso->movimiento;
        $this->assertSame(
            ['reembolso_proveedor', 'in', '300.00', 'reembolso_proveedor', $reembolso->id, 'proveedor', $this->proveedor->id, 'confirmed', $sesion->id],
            [$movimiento->type, $movimiento->direction, $movimiento->amount, $movimiento->reference_type, (int) $movimiento->reference_id,
                $movimiento->counterparty_type, (int) $movimiento->counterparty_id, $movimiento->status, (int) $movimiento->cash_session_id]
        );
        $this->assertSame(400.0, $this->esperado($sesion));
    }

    public function test_topes_del_reembolso(): void // CA12
    {
        [$compra] = $this->aFavor();
        $e = $this->rechazo(fn () => $this->reembolsar($compra, '300.01'), 'Solo se pueden reembolsar PEN 300.00');
        $this->assertSame('300.00', $e->extra['maximo']);

        $sinFavor = $this->compra('100.00');
        $this->rechazo(fn () => $this->reembolsar($sinFavor, '1.00'), "{$sinFavor->numero} no tiene saldo a favor");

        // Con un pago por aprobar y saldo positivo: todavía no se pagó.
        $this->cajaConFondo();
        $this->exigirAprobacionSobre('10.00');
        $porAprobar = $this->compra('500.00');
        $this->porCaja('500.00', [[$porAprobar, '400.00']]);
        $this->nota($porAprobar, '100.00');
        $this->rechazo(fn () => $this->reembolsar($porAprobar, '1.00'), 'no tiene saldo a favor');

        $this->reembolsar($compra, '200.00');
        $this->rechazo(fn () => $this->reembolsar($compra, '100.01'), 'Solo se pueden reembolsar PEN 100.00');
        $this->reembolsar($compra, '100.00');
        $this->assertEstado($compra, 'pagado', '0.00');

        $this->rechazo(fn () => $this->reembolsar($compra, '0'), 'mayor a cero');
        $this->rechazo(fn () => $this->reembolsar($compra, '1.005'), 'dos decimales');
        $this->rechazo(fn () => $this->reembolsar($compra, '1.00', ['fecha' => '2026-03-16']), 'no puede ser futura');
    }

    public function test_reembolso_de_lo_a_cuenta(): void // CA13
    {
        $pago = $this->porBanco('500.00');
        $this->reembolsar($pago, '200.00');

        $ficha = $this->fichaPago($pago->id);
        $this->assertSame(['300.00', '200.00'], [$ficha['a_cuenta'], $ficha['reembolsado']]);
        $this->assertCount(1, $ficha['reembolsos']);

        $compra = $this->compra('400.00');
        $this->rechazo(fn () => $this->aplicar($pago, $compra, '400.00'), 'solo le quedan 300.00');
        $e = $this->rechazo(fn () => $this->reembolsar($pago, '300.01'), 'Solo se pueden reembolsar PEN 300.00');
        $this->assertSame('300.00', $e->extra['maximo']);
        $this->aplicar($pago, $compra, '300.00');
        $this->assertSame('0.00', $this->fichaPago($pago->id)['a_cuenta']);

        // Desaplicar sí se puede: lo disponible crece.
        $this->servicio()->desaplicar($pago->aplicaciones()->sole(), 'Prueba', $this->user);
        $this->assertSame('300.00', $this->fichaPago($pago->id)['a_cuenta']);
    }

    public function test_disponible_igual_en_todas_partes(): void // CA13
    {
        $compra = $this->compra('100.00');
        $pago = $this->porBanco('500.00', [[$compra, '100.00']]);
        $this->reembolsar($pago, '150.00');
        $anulado = $this->reembolsar($pago, '50.00');
        $this->reembolsos()->anular($anulado, 'No llegó', $this->user);

        $comprobar = function (string $esperado) use ($pago) {
            $this->assertSame($esperado, (string) $this->servicio()->disponibles([$pago->id])[$pago->id], 'disponibles');
            $porProveedor = $this->servicio()->sinAplicarPorProveedor([$this->proveedor->id]);
            $this->assertSame($esperado, (string) $porProveedor[$this->proveedor->id]['PEN']['a_cuenta']->toScale(2), 'agregado');
            $this->assertSame($esperado, $this->fichaPago($pago->id)['a_cuenta'], 'ficha');
            $this->assertSame($esperado, $this->listadoDePagos()[0]['a_cuenta'], 'listado');
        };

        $comprobar('250.00');
        $this->assertSame('250.00', $this->detalle()['fichas'][0]['pagos'][0]['a_cuenta']);
        $this->assertSame('250.00', $this->cuentasPorPagar()['monedas'][0]['a_cuenta']);
        $this->assertSame([$pago->id], array_column($this->listadoDePagos(['con_saldo' => '1']), 'id'));

        $this->reembolsar($pago, '250.00');
        $this->assertSame('0.00', $this->fichaPago($pago->id)['a_cuenta']);
        $this->assertSame([], $this->listadoDePagos(['con_saldo' => '1']));
        $this->assertSame([], $this->cuentasPorPagar());
    }

    public function test_pago_no_reembolsable(): void // CA14
    {
        $this->cajaConFondo();
        $this->exigirAprobacionSobre('10.00');
        $pendiente = $this->porCaja('100.00');
        $this->assertSame(PagoProveedor::PENDIENTE, $pendiente->estado);
        $this->rechazo(fn () => $this->reembolsar($pendiente, '10.00'), 'espera aprobación');

        $anulado = $this->porBanco('100.00');
        $this->servicio()->anular($anulado, 'No salió', $this->user);
        $this->rechazo(fn () => $this->reembolsar($anulado, '10.00'), 'está anulado');
    }

    public function test_destino_invalido(): void // CA15
    {
        [$compra, $pago, $nota] = $this->aFavor();
        $libre = $this->porBanco('100.00');
        $otro = $this->proveedor('T140 Otro SAC', '20140000002');

        $this->rechazo(fn () => $this->reembolsar($compra, '1.00', ['proveedor_id' => $otro->id]), 'de otro proveedor');
        $this->rechazo(fn () => $this->reembolsar($libre, '1.00', ['proveedor_id' => $otro->id]), 'de otro proveedor');
        $this->rechazo(fn () => $this->reembolsar($compra, '1.00', ['moneda' => 'USD']), 'está en PEN');
        $this->rechazo(fn () => $this->reembolsar($libre, '1.00', ['moneda' => 'USD']), 'está en PEN');
        $this->rechazo(fn () => $this->reembolsar($nota, '1.00'), 'en la compra que modifica');
        $this->rechazo(fn () => $this->reembolsar($compra, '1.00', ['pago_proveedor_id' => $pago->id]), 'solo uno');
        $this->rechazo(fn () => $this->reembolsar($compra, '1.00', ['compra_id' => null]), 'solo uno');
        $this->rechazo(fn () => $this->reembolsar($compra, '1.00', ['compra_id' => 999999]), 'no existe');
        $this->rechazo(fn () => $this->reembolsar($libre, '1.00', ['pago_proveedor_id' => 999999]), 'no existe');
        $this->rechazo(fn () => $this->reembolsar($compra, '1.00', ['compra_id' => 'x']), 'La compra no es');

        // En USD el T.C. es obligatorio.
        $dolares = $this->porBanco('100.00', [], ['moneda' => 'USD', 'tipo_cambio' => '3.7']);
        $this->rechazo(fn () => $this->reembolsar($dolares, '1.00', ['tipo_cambio' => null]), 'En dólares se necesita el tipo de cambio.');
        $this->assertSame('3.7000', $this->reembolsar($dolares, '1.00')->fresh()->tipo_cambio);
    }

    public function test_operacion_repetida(): void // CA16
    {
        [$compra] = $this->aFavor();
        $primero = $this->reembolsar($compra, '100.00', ['numero_operacion' => 'op 01']);
        $this->assertSame('OP01', $primero->numero_operacion);

        $e = $this->rechazo(fn () => $this->reembolsar($compra, '50.00', ['numero_operacion' => 'OP01']), 'ya está registrado', 409);
        $this->assertSame(
            ['id' => $primero->id, 'fecha' => self::HOY, 'monto' => '100.00', 'moneda' => 'PEN', 'proveedor' => 'T140 Mayorista SAC'],
            $e->extra['existente']
        );

        $this->reembolsos()->anular($primero, 'Mal escrito', $this->user);
        $this->assertSame('OP01', $this->reembolsar($compra, '100.00', ['numero_operacion' => 'OP01'])->numero_operacion);
        $this->rechazo(fn () => $this->reembolsar($compra, '1.00', ['cuenta_bancaria_id' => $this->cuentaUsd->id]), 'La cuenta es en USD y el reembolso en PEN');
    }

    public function test_anular_reembolso(): void // CA17
    {
        [$compra] = $this->aFavor();
        $sesion = $this->cajaConFondo('100.00');
        $enCaja = $this->reembolsarEnCaja($compra, '100.00');
        $porBanco = $this->reembolsar($compra, '200.00');
        $this->rechazo(fn () => $this->reembolsos()->anular($enCaja, '  ', $this->user), 'motivo');

        $this->reembolsos()->anular($enCaja, 'No entró', $this->user);
        $original = $enCaja->movimiento->fresh();
        $this->assertNotNull($original->corrected_by);
        $correccion = CashMovement::where('corrected_movement_id', $original->id)->sole();
        $this->assertSame(['correction', 'out', '100.00'], [$correccion->type, $correccion->direction, $correccion->amount]);
        $this->assertSame(100.0, $this->esperado($sesion));
        $this->assertSame(ReembolsoProveedor::ANULADO, $enCaja->fresh()->estado);

        $movimientos = CashMovement::count();
        $this->reembolsos()->anular($porBanco, 'No llegó', $this->user);
        $this->assertSame($movimientos, CashMovement::count());
        $this->assertSame(['anulado', 'No llegó', $this->user->id], array_values($porBanco->fresh()->only(['estado', 'motivo_anulacion', 'anulado_por'])));
        $this->assertEstado($compra, 'pagado', '-300.00');
        $this->rechazo(fn () => $this->reembolsos()->anular($porBanco, 'Otra vez', $this->user), 'ya está anulado');
    }

    public function test_anular_no_deja_la_caja_en_negativo(): void
    {
        [$compra] = $this->aFavor();
        $this->cajaConFondo('100.00');
        $reembolso = $this->reembolsarEnCaja($compra, '300.00');
        // El efectivo ya salió en otro pago.
        $this->porCaja('350.00');

        $this->rechazo(fn () => $this->reembolsos()->anular($reembolso, 'Error', $this->user), 'en negativo');
        $this->assertSame(ReembolsoProveedor::VIGENTE, $reembolso->fresh()->estado);
    }

    public function test_el_reembolso_traba_lo_que_lo_sostiene(): void // CA18
    {
        [$compra, $pago, $nota] = $this->aFavor();
        $reembolso = $this->reembolsar($compra, '100.00');
        $libre = $this->porBanco('500.00');
        $delPago = $this->reembolsar($libre, '100.00');

        $trabado = fn (callable $accion) => $this->rechazo($accion, 'anúlalos primero');
        $trabado(fn () => $this->anularCompra($compra));
        $trabado(fn () => $this->editarCompra($compra, '999.00'));
        $trabado(fn () => $this->editarCompra($compra, '1000.00', ['moneda' => 'USD', 'tipo_cambio' => '3.7']));
        $trabado(fn () => $this->editarCompra($compra, '1000.00', ['proveedor_id' => $this->proveedor('T140 Otro SAC', '20140000001')->id]));
        $trabado(fn () => $this->anularCompra($nota));
        $trabado(fn () => $this->editarCompra($nota, '200.00'));
        // Decisión del usuario (construcción): tampoco el pago que sostiene el saldo a favor.
        $trabado(fn () => $this->servicio()->desaplicar($pago->aplicaciones()->sole(), 'x', $this->user));
        $trabado(fn () => $this->servicio()->anular($pago, 'x', $this->user));
        $trabado(fn () => $this->servicio()->anular($libre, 'x', $this->user));
        // Lo que no toca el saldo se sigue editando.
        $this->editarCompra($compra, '1000.00', ['observaciones' => 'Se puede']);
        $this->editarCompra($nota, '300.00', ['observaciones' => 'Se puede']);

        $this->reembolsos()->anular($reembolso, 'x', $this->user);
        $this->reembolsos()->anular($delPago, 'x', $this->user);

        $this->servicio()->anular($libre, 'x', $this->user);
        $this->editarCompra($nota, '200.00');
        $this->anularCompra($nota);
        $this->servicio()->desaplicar($pago->aplicaciones()->sole(), 'x', $this->user);
        $this->editarCompra($compra, '999.00');
        $this->assertTrue($this->anularCompra($compra)->estaAnulada());
    }

    public function test_permisos(): void // CA19
    {
        $rutas = ['/api/compras/reembolsos' => 'permission:compras.pagar', '/api/compras/reembolsos/1/anular' => 'permission:compras.anular'];
        foreach ($rutas as $uri => $permiso) {
            $middleware = Route::getRoutes()->match(Request::create($uri, 'POST'))->gatherMiddleware();
            foreach (['tenant', 'modulo:compras', $permiso] as $esperado) {
                $this->assertContains($esperado, $middleware, $uri);
            }
        }

        $this->usuario(['compras.ver']);
        foreach (['compras.pagar', 'compras.anular'] as $permiso) {
            try {
                app(PermissionMiddleware::class)->handle(new Request, fn () => response('ok'), $permiso, 'api');
                $this->fail("Sin {$permiso} no debía pasar");
            } catch (UnauthorizedException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_caja_no_lo_edita(): void // CA20
    {
        [$compra] = $this->aFavor();
        $this->cajaConFondo();
        $reembolso = $this->reembolsarEnCaja($compra, '100.00');
        $caja = app(CashMovementController::class);
        $edicion = new Request(['payment_method_id' => $this->efectivo->id, 'amount' => 50, 'concept_id' => 1, 'description' => 'x']);

        $this->rechazo(fn () => $caja->update($edicion, (string) $reembolso->cash_movement_id), 'Solo se pueden editar/eliminar');
        $this->rechazo(fn () => $caja->destroy((string) $reembolso->cash_movement_id), 'Solo se pueden editar/eliminar');
    }
}
