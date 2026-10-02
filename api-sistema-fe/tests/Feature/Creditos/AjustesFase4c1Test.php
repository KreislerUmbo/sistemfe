<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\TipoMovimientoSaldoFavor;
use App\Http\Controllers\Creditos\ClienteCreditoController;
use App\Http\Controllers\Creditos\CreditoController;
use App\Http\Requests\Creditos\CreditoDatosRequest;
use App\Http\Requests\Creditos\DevolverSaldoRequest;
use App\Models\Cash\CashSession;
use App\Models\Creditos\CreditoSaldoFavorMovimiento;
use App\Models\User;
use App\Services\Creditos\ActivacionService;
use App\Services\Creditos\AnulacionPagoService;
use App\Services\Creditos\CajaCredito;
use App\Services\Creditos\ClienteCreditoService;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\CreditoBorradorService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\EscalamientoService;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\SaldoAFavorService;
use Database\Seeders\CreditosRolesSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Fase 4c.1: ajustes de la revisión previa a reportes (saldo a favor, cartera, concurrencia, roles). */
final class AjustesFase4c1Test extends CreditosTestCase
{
    private User $admin;
    private CashSession $caja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario();
        $this->caja = $this->abrirCaja($this->admin);
    }

    // ---- 1. Saldo a favor: ver, usar y devolver ----

    public function test_devolver_saldo_a_favor_sale_de_caja_y_baja_el_saldo(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-01-31');
        app(CobroService::class)->cobrar($credito, new SolicitudCobro(70_000, DestinoExcedente::SaldoAFavor, $this->efectivo->id, 'sf-1'), $this->admin);
        $this->assertSame(10_000, app(SaldoAFavorService::class)->saldo($credito->cliente_id));

        $respuesta = $this->devolver($credito->cliente_id, '60', 'devolver-0001');

        $this->assertSame('40.00', $respuesta['saldo']);
        $this->assertSame('devolucion', $respuesta['movimientos'][0]['tipo']);
        $movimiento = CreditoSaldoFavorMovimiento::where('tipo', TipoMovimientoSaldoFavor::Devolucion)->firstOrFail();
        $salida = DB::table('cash_movements')->where('id', $movimiento->cash_movement_id)->first();
        $this->assertSame(CajaCredito::DEVOLUCION_SALDO_FAVOR, $salida->reference_type);
        $this->assertSame('out', $salida->direction);
        $this->assertSame('60.00', (string) $salida->amount);
        $this->assertSame($this->caja->id, (int) $salida->cash_session_id);
        // La misma clave no devuelve dos veces.
        $this->assertSame('40.00', $this->devolver($credito->cliente_id, '60', 'devolver-0001')['saldo']);
    }

    public function test_no_se_devuelve_mas_que_el_saldo(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-01-31');
        app(CobroService::class)->cobrar($credito, new SolicitudCobro(65_000, DestinoExcedente::SaldoAFavor, $this->efectivo->id, 'sf-2'), $this->admin);

        $this->expectException(HttpException::class);
        $this->devolver($credito->cliente_id, '50.10', 'devolver-0002');
    }

    public function test_un_pago_cuyo_saldo_ya_se_devolvio_no_se_anula(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-01-31');
        $pago = app(CobroService::class)->cobrar($credito, new SolicitudCobro(70_000, DestinoExcedente::SaldoAFavor, $this->efectivo->id, 'sf-3'), $this->admin);
        $this->devolver($credito->cliente_id, '100', 'devolver-0003');

        $this->expectException(HttpException::class);
        app(AnulacionPagoService::class)->anular($pago, 'Error de digitación', $this->admin);
    }

    public function test_la_ficha_y_el_resumen_muestran_el_saldo(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-01-31');
        app(CobroService::class)->cobrar($credito, new SolicitudCobro(70_000, DestinoExcedente::SaldoAFavor, $this->efectivo->id, 'sf-4'), $this->admin);
        Auth::guard('api')->setUser($this->admin);

        $this->assertSame('100.00', app(ClienteCreditoController::class)->ficha($credito->cliente_id)->getData(true)['saldo_a_favor']);
        $this->assertSame('100.00', app(ClienteCreditoController::class)->resumen($credito->cliente_id)->getData(true)['saldo_a_favor']);
    }

    // ---- 2. Cartera del cliente al crear, previsualizar y migrar ----

    public function test_un_asesor_no_crea_ni_previsualiza_creditos_de_clientes_ajenos(): void
    {
        $asesor = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        $propio = $this->cliente();
        $ajeno = $this->cliente();
        app(ClienteCreditoService::class)->asignarCartera($propio, $asesor, null, $this->admin);
        Auth::guard('api')->setUser($asesor);
        $controller = app(CreditoController::class);

        $preview = $controller->preview($this->peticion(CreditoDatosRequest::class, $this->cuerpo($propio->id), $asesor))->getData(true);
        $this->assertArrayHasKey('cronograma', $preview);

        try {
            $controller->preview($this->peticion(CreditoDatosRequest::class, $this->cuerpo($ajeno->id), $asesor));
            $this->fail('Debía responder 404 con un cliente de otra cartera.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        try {
            $controller->store($this->peticion(\App\Http\Requests\Creditos\CrearCreditoRequest::class, [...$this->cuerpo($ajeno->id), 'clave_idempotencia' => 'ajeno-00001'], $asesor));
            $this->fail('Debía responder 404 al crear para un cliente de otra cartera.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ---- 3. Editar un borrador que ya se activó ----

    public function test_editar_un_borrador_ya_activado_con_el_modelo_viejo_se_rechaza(): void
    {
        $cliente = $this->cliente();
        $borrador = app(CreditoBorradorService::class)->crear($this->datos($cliente->id), $this->admin);
        $viejo = clone $borrador;   // lo que tenía en memoria la otra pestaña
        app(ActivacionService::class)->activar($borrador, $this->admin);

        try {
            app(CreditoBorradorService::class)->actualizar($viejo, $this->datos($cliente->id, capital: 900_000));
            $this->fail('Debía rechazar editar un crédito que ya está activo.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame('5000.00', (string) $borrador->fresh()->monto_capital);
    }

    // ---- 5. Rol Asesor de créditos ----

    public function test_el_rol_asesor_registra_clientes_coloca_y_cobra_pero_no_ve_todo(): void
    {
        $roles = (new CreditosRolesSeeder())->roles();

        $this->assertArrayHasKey('Asesor de créditos', $roles);
        $asesor = $roles['Asesor de créditos'];
        foreach (['creditos.ver', 'creditos.crear', 'creditos.cobrar', 'register_client', 'list_client', 'edit_client', 'cash.open_session'] as $permiso) {
            $this->assertContains($permiso, $asesor);
        }
        $this->assertNotContains('creditos.ver_todos', $asesor);
    }

    // ---- 6. Castigo automático tolerante a errores ----

    public function test_el_castigo_automatico_sigue_si_un_credito_falla(): void
    {
        $bien = $this->activo($this->admin, null, $this->datos($this->cliente()->id, capital: 100_000, cuotas: 1));
        $mal = $this->activo($this->admin, null, $this->datos($this->cliente()->id, capital: 100_000, cuotas: 1));
        // El castigo de un crédito falla; el resto debe seguir castigándose.
        $real = app(\App\Services\Creditos\CastigoService::class);
        $this->mock(\App\Services\Creditos\CastigoService::class, function ($mock) use ($real, $mal): void {
            $mock->shouldReceive('castigar')->andReturnUsing(function ($credito, ...$resto) use ($real, $mal) {
                if ($credito->id === $mal->id) {
                    throw new \RuntimeException('fallo simulado');
                }

                return $real->castigar($credito, ...$resto);
            });
        });
        $this->hoy('2026-05-02');

        $castigados = app(EscalamientoService::class)->ejecutar(Fecha::desdeTexto('2026-05-02'));

        $this->assertSame(1, $castigados);
        $this->assertSame('castigado', $bien->fresh()->estado->value);
        $this->assertSame('activo', $mal->fresh()->estado->value);
    }

    // ---- Traspaso de cartera (asesor que se va) ----

    public function test_traspasar_toda_la_cartera_de_un_asesor_a_otro(): void
    {
        $sale = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        $entra = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        $c1 = $this->cliente();
        $c2 = $this->cliente();
        $servicio = app(ClienteCreditoService::class);
        $servicio->asignarCartera($c1, $sale, null, $this->admin);
        $servicio->asignarCartera($c2, $sale, null, $this->admin);
        $credito = $this->activo($this->admin, $c1);
        $this->hoy('2026-01-10');

        $this->assertSame(2, $servicio->traspasarCartera($sale->id, $entra, 'ambas', $this->admin));

        $alcance = app(\App\Services\Creditos\AlcanceCartera::class);
        $this->assertFalse($alcance->puedeVerCliente($c1->id, $sale));
        $this->assertTrue($alcance->puedeVerCliente($c2->id, $entra));
        $this->assertSame(['asesor_id' => $entra->id, 'cobrador_id' => $entra->id, 'asesor_cobra' => true], $servicio->cartera($c1));
        $this->assertSame(0, $servicio->clientesEnCartera($sale->id));
        // Quien colocó el crédito no cambia (comisiones y reportes).
        $this->assertSame($credito->asesor_id, $credito->fresh()->asesor_id);
        $this->assertSame(1, DB::table('credito_auditoria')->where('accion', 'cartera.traspasar')->count());
    }

    public function test_se_traspasa_la_cartera_de_un_usuario_ya_eliminado(): void
    {
        $sale = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        $entra = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        $cliente = $this->cliente();
        app(ClienteCreditoService::class)->asignarCartera($cliente, $sale, null, $this->admin);
        $sale->delete();   // eliminado antes de existir la protección (dato heredado)

        $titular = collect(app(ClienteCreditoService::class)->titularesCartera())->firstWhere('id', $sale->id);
        $this->assertTrue($titular['eliminado']);
        $this->assertSame(1, $titular['asesor']);

        $this->assertSame(1, app(ClienteCreditoService::class)->traspasarCartera($sale->id, $entra, 'ambas', $this->admin));
    }

    public function test_el_traspaso_valida_a_quien_recibe(): void
    {
        $sale = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        app(ClienteCreditoService::class)->asignarCartera($this->cliente(), $sale, null, $this->admin);

        foreach ([$sale, $this->usuario(['creditos.ver'])] as $hacia) {
            try {
                app(ClienteCreditoService::class)->traspasarCartera($sale->id, $hacia, 'ambas', $this->admin);
                $this->fail('Debía rechazar el traspaso.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_no_se_elimina_ni_desactiva_a_un_usuario_con_cartera(): void
    {
        $asesor = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        app(ClienteCreditoService::class)->asignarCartera($this->cliente(), $asesor, null, $this->admin);
        Auth::guard('api')->setUser($this->admin);
        $usuarios = app(\App\Http\Controllers\User\UserController::class);

        $this->assertSame(405, $usuarios->destroy((string) $asesor->id)->getData(true)['code']);
        $this->assertNull($asesor->fresh()->deleted_at);

        $desactivar = new \Illuminate\Http\Request([
            'name' => $asesor->name, 'email' => $asesor->email, 'role_id' => (string) $asesor->role_id, 'state' => '2', 'password' => '',
        ]);
        $this->assertSame(405, $usuarios->update($desactivar, (string) $asesor->id)->getData(true)['code']);
        $this->assertNotSame(2, (int) $asesor->fresh()->state);
    }

    public function test_los_selectores_de_cartera_no_ofrecen_usuarios_inactivos(): void
    {
        $inactivo = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        $inactivo->update(['state' => 2]);

        $lista = app(ClienteCreditoService::class)->usuariosCartera();

        $this->assertNotContains($inactivo->id, array_column($lista['asesores'], 'id'));
        $this->assertNotContains($inactivo->id, array_column($lista['cobradores'], 'id'));
    }

    // ---- Ayudas ----

    /** @return array<string, mixed> */
    private function devolver(int $clienteId, string $monto, string $clave): array
    {
        Auth::guard('api')->setUser($this->admin);

        return app(ClienteCreditoController::class)->devolverSaldo($this->peticion(DevolverSaldoRequest::class, [
            'monto' => $monto, 'payment_method_id' => $this->efectivo->id, 'motivo' => 'Lo pidió el cliente', 'clave_idempotencia' => $clave,
        ], $this->admin), $clienteId)->getData(true);
    }

    /** @return array<string, mixed> */
    private function cuerpo(int $clienteId): array
    {
        return [
            'cliente_id' => $clienteId, 'monto_capital' => '1000', 'tasa_interes' => '20', 'unidad_tasa' => 'total',
            'frecuencia_unidad' => 'dia', 'frecuencia_intervalo' => 30, 'numero_cuotas' => 2, 'fecha_desembolso' => '2026-01-01',
            'payment_method_id' => $this->efectivo->id,
        ];
    }

    /** @template T of FormRequest @param class-string<T> $clase @return T */
    private function peticion(string $clase, array $datos, User $usuario): FormRequest
    {
        $request = $clase::create('/api/test', 'POST', $datos);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(fn () => $usuario);
        $request->validateResolved();

        return $request;
    }
}
