<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\TipoCastigo;
use App\Http\Controllers\Creditos\ClienteCreditoController;
use App\Http\Requests\Creditos\TraspasarCarteraRequest;
use App\Models\Creditos\Credito;
use App\Models\User;
use App\Services\Creditos\CastigoService;
use App\Services\Creditos\ClienteCreditoService;
use App\Services\Creditos\CorreccionService;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Reportes\Periodo;
use App\Services\Creditos\Reportes\ReportesCarteraService;
use App\Services\Creditos\Reportes\ReportesFinancierosService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Cambiar asesor" (08-oct-2026): créditos registrados por soporte a nombre de la asesora. Uno por
 * uno desde el detalle, o en bloque desde "Traspasar cartera".
 */
final class CambiarAsesorTest extends CreditosTestCase
{
    private const ASESOR = ['creditos.ver', 'creditos.crear', 'creditos.cobrar'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario();
        $this->abrirCaja($this->admin);
    }

    public function test_cambia_el_asesor_de_un_credito_y_queda_auditado(): void
    {
        $credito = $this->activo($this->admin);
        $shirley = $this->usuario(self::ASESOR);

        app(ClienteCreditoService::class)->cambiarAsesor($credito, $shirley, 'Lo registró soporte', $this->admin);

        $this->assertSame($shirley->id, $credito->fresh()->asesor_id);
        $auditoria = DB::table('credito_auditoria')->where('credito_id', $credito->id)->where('accion', 'credito.cambiar_asesor')->sole();
        $this->assertSame('Lo registró soporte', $auditoria->motivo);
        $this->assertSame($shirley->name, json_decode($auditoria->despues, true)['asesor']);
        $this->assertSame($this->admin->name, json_decode($auditoria->antes, true)['asesor']);
    }

    public function test_tambien_en_un_credito_castigado(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-03-01');
        app(CastigoService::class)->castigar($credito, TipoCastigo::Manual, 'Incobrable', $this->admin);
        $shirley = $this->usuario(self::ASESOR);

        app(ClienteCreditoService::class)->cambiarAsesor($credito, $shirley, 'Pasa a Shirley', $this->admin);

        $this->assertSame($shirley->id, $credito->fresh()->asesor_id);
    }

    public function test_no_cambia_un_credito_finalizado_ni_anulado(): void
    {
        $shirley = $this->usuario(self::ASESOR);
        $anulado = $this->activo($this->admin);
        app(CorreccionService::class)->anular($anulado, 'Error', $this->admin);

        $this->assertRechazo(422, fn () => app(ClienteCreditoService::class)->cambiarAsesor($anulado, $shirley, 'x', $this->admin));
        $this->assertSame($this->admin->id, $anulado->fresh()->asesor_id);
    }

    public function test_el_nuevo_asesor_debe_poder_registrar_creditos_y_ser_distinto(): void
    {
        $credito = $this->activo($this->admin);

        $this->assertRechazo(422, fn () => app(ClienteCreditoService::class)->cambiarAsesor($credito, $this->usuario(['creditos.ver']), 'x', $this->admin));
        $this->assertRechazo(422, fn () => app(ClienteCreditoService::class)->cambiarAsesor($credito, $this->admin, 'x', $this->admin));
    }

    public function test_el_reporte_por_asesor_refleja_el_cambio(): void
    {
        $credito = $this->activo($this->admin);   // desembolso 01/01/2026
        $shirley = $this->usuario(self::ASESOR);
        app(ClienteCreditoService::class)->cambiarAsesor($credito, $shirley, 'Pasa a Shirley', $this->admin);

        $reporte = app(ReportesFinancierosService::class)->porAsesor(
            Periodo::entre(Fecha::desdeTexto('2026-01-01'), Fecha::desdeTexto('2026-01-31')), $this->admin, app(ReportesCarteraService::class),
        );
        $fila = collect($reporte['filas'] ?? $reporte)->firstWhere('asesor_id', $shirley->id);
        $this->assertSame(1, $fila['colocados']);
    }

    // ---- En bloque, desde "Traspasar cartera" ----

    public function test_el_traspaso_con_creditos_pasa_clientes_y_creditos_activos(): void
    {
        $soporte = $this->usuario(self::ASESOR);
        $this->abrirCaja($soporte);
        $shirley = $this->usuario(self::ASESOR);
        $cliente = $this->cliente();
        app(ClienteCreditoService::class)->asignarCartera($cliente, $soporte, null, $this->admin);
        $a = $this->activo($soporte, $cliente);
        $b = $this->activo($soporte, $this->cliente());
        $anulado = $this->activo($soporte, $this->cliente());
        app(CorreccionService::class)->anular($anulado, 'Error', $this->admin);

        $titular = collect(app(ClienteCreditoService::class)->titularesCartera())->firstWhere('id', $soporte->id);
        $this->assertSame(2, $titular['creditos']);

        $respuesta = $this->traspasar($soporte, $shirley, ['con_creditos' => true, 'motivo_creditos' => 'Los registró soporte'], 'traspaso-1');

        $this->assertSame([1, 2], [$respuesta['clientes'], $respuesta['creditos']]);
        $this->assertSame([$shirley->id, $shirley->id, $soporte->id], [$a->fresh()->asesor_id, $b->fresh()->asesor_id, $anulado->fresh()->asesor_id]);
        $this->assertSame(2, DB::table('credito_auditoria')->where('accion', 'credito.cambiar_asesor')->count());
    }

    public function test_sin_la_casilla_los_creditos_conservan_su_asesor(): void
    {
        $soporte = $this->usuario(self::ASESOR);
        $this->abrirCaja($soporte);
        $shirley = $this->usuario(self::ASESOR);
        $cliente = $this->cliente();
        app(ClienteCreditoService::class)->asignarCartera($cliente, $soporte, null, $this->admin);
        $credito = $this->activo($soporte, $cliente);

        $respuesta = $this->traspasar($soporte, $shirley, [], 'traspaso-2');

        $this->assertSame([1, 0], [$respuesta['clientes'], $respuesta['creditos']]);
        $this->assertSame($soporte->id, $credito->fresh()->asesor_id);
    }

    public function test_quien_solo_coloco_creditos_sin_clientes_igual_los_traspasa(): void
    {
        $soporte = $this->usuario(self::ASESOR);
        $this->abrirCaja($soporte);
        $shirley = $this->usuario(self::ASESOR);
        $credito = $this->activo($soporte);   // el cliente quedó sin cartera de soporte
        DB::table('cartera_asignaciones')->where('usuario_id', $soporte->id)->delete();

        $respuesta = $this->traspasar($soporte, $shirley, ['con_creditos' => true, 'motivo_creditos' => 'Soporte'], 'traspaso-3');

        $this->assertSame([0, 1], [$respuesta['clientes'], $respuesta['creditos']]);
        $this->assertSame($shirley->id, $credito->fresh()->asesor_id);
    }

    public function test_pasar_creditos_exige_motivo(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->peticion(['desde_usuario_id' => 1, 'hacia_usuario_id' => $this->admin->id, 'con_creditos' => true, 'clave_idempotencia' => 'sin-motivo-0001']);
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function traspasar(User $desde, User $hacia, array $extra, string $clave): array
    {
        Auth::guard('api')->setUser($this->admin);

        return app(ClienteCreditoController::class)->traspasarCartera($this->peticion([
            'desde_usuario_id' => $desde->id, 'hacia_usuario_id' => $hacia->id, 'funciones' => 'ambas',
            'clave_idempotencia' => str_pad($clave, 16, '-'), ...$extra,
        ]))->getData(true);
    }

    /** @param array<string, mixed> $datos */
    private function peticion(array $datos): FormRequest
    {
        $request = TraspasarCarteraRequest::create('/api/test', 'POST', $datos);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(fn () => $this->admin);
        $request->validateResolved();

        return $request;
    }

    private function assertRechazo(int $estado, \Closure $accion): void
    {
        try {
            $accion();
            $this->fail("Se esperaba un rechazo {$estado}.");
        } catch (HttpException $e) {
            $this->assertSame($estado, $e->getStatusCode(), $e->getMessage());
        }
    }
}
