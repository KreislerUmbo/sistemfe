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
