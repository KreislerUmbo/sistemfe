<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\TipoCastigo;
use App\Http\Controllers\Creditos\ReporteCreditoController;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoCarteraDiaria;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\AnulacionPagoService;
use App\Services\Creditos\CastigoService;
use App\Services\Creditos\ClienteCreditoService;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Reportes\FotoCarteraService;
use App\Services\Creditos\Reportes\ReportesCarteraService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fase 4d — reportes con un dataset conocido (Plan 1.16: cada indicador con resultado exacto).
 * Créditos de ejemplo (datos()): 5,000 al 20% total en 10 cuotas de 600 cada 30 días, desde el
 * 01/01/2026 (cuota 1 vence el 31/01). A: sin pagos. B: paga la cuota 1 el 31/01.
 */
final class ReportesCreditoTest extends CreditosTestCase
{
    private User $admin;
    private Credito $a;
    private Credito $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario();
        $this->abrirCaja($this->admin);
        $this->a = $this->activo($this->admin);
        $this->b = $this->activo($this->admin);
    }

    private function cobrar(Credito $credito, int $centavos, string $clave): CreditoPago
    {
        return app(CobroService::class)->cobrar($credito, new SolicitudCobro($centavos, DestinoExcedente::Devolver, $this->efectivo->id, $clave), $this->admin);
    }

    /** @return array<string, mixed> */
    private function reporte(string $reporte, array $query = [], ?User $usuario = null): array
    {
        Auth::guard('api')->setUser($usuario ?? $this->admin);

        return app(ReporteCreditoController::class)->show(new Request($query), $reporte)->getData(true);
    }

    public function test_panel_cartera_riesgo_cobranza_y_conciliacion(): void
    {
        $this->hoy('2026-01-31');
        $this->cobrar($this->b, 60_000, 'rep-b1');
        $conciliacion = app(ReportesCarteraService::class)->conciliacion(\App\Services\Creditos\Reportes\Periodo::dia(Fecha::desdeTexto('2026-01-31')));
        $this->assertSame(['creditos' => '600.00', 'caja' => '600.00', 'cuadra' => true, 'diferencia' => '0.00'], $conciliacion);

        $this->hoy('2026-02-05');   // A: cuota 1 vencida hace 5 días → 100 de mora
        Auth::guard('api')->setUser($this->admin);
        $panel = app(ReporteCreditoController::class)->panel()->getData(true);

        $this->assertSame(['saldo_capital' => '9500.00', 'creditos' => 2, 'clientes' => 2], $panel['cartera']);
        $this->assertSame(['porcentaje' => '52.6', 'saldo_capital' => '5000.00', 'creditos' => 1], $panel['riesgo']);   // 5,000 / 9,500
        // Por cobrar = lo mismo que la Cobranza del día: cuota 1 + mora + la próxima por vencer de A.
        $this->assertSame('1300.00', $panel['cobranza_hoy']['por_cobrar']);
        $this->assertSame('0.00', $panel['cobranza_hoy']['cobrado']);
        $this->assertCount(14, $panel['cobrado_dias']);
        $this->assertSame('600.00', collect($panel['cobrado_dias'])->firstWhere('fecha', '2026-01-31')['monto']);
        $this->assertSame([$this->a->id], array_column($panel['mas_atrasados'], 'credito_id'));
        $this->assertSame('100.00', $panel['mas_atrasados'][0]['mora']);
    }

    public function test_cartera_y_morosidad(): void
    {
        $this->hoy('2026-01-31');
        $this->cobrar($this->b, 60_000, 'rep-b2');
        $this->hoy('2026-02-05');

        $cartera = $this->reporte('cartera');
        $this->assertCount(2, $cartera['filas']);
        $this->assertSame($this->a->id, $cartera['filas'][0]['credito_id']);   // el más atrasado primero
        $this->assertSame(['creditos' => 2, 'capital_prestado' => '10000.00', 'saldo_capital' => '9500.00', 'saldo_interes' => '1900.00', 'mora' => '100.00'], $cartera['totales']);
        $this->assertCount(1, $this->reporte('cartera', ['rango' => '1-7'])['filas']);
        // Opciones del filtro de asesor: salen antes de filtrar (0 = sin asesor).
        $this->assertSame([['id' => 0, 'nombre' => 'Sin asesor']], $cartera['opciones']['asesores']);
        $sinAsesor = $this->reporte('cartera', ['asesor_id' => 0, 'rango' => '1-7']);
        $this->assertCount(1, $sinAsesor['filas']);
        $this->assertSame([['id' => 0, 'nombre' => 'Sin asesor']], $sinAsesor['opciones']['asesores']);

        $morosidad = $this->reporte('morosidad')['total'];
        $rangos = collect($morosidad['rangos'])->keyBy('rango');
        $this->assertSame(['rango' => 'al_dia', 'creditos' => 1, 'saldo' => '4500.00'], $rangos['al_dia']);
        $this->assertSame(['rango' => '1-7', 'creditos' => 1, 'saldo' => '5000.00'], $rangos['1-7']);
        $this->assertSame('52.6', $morosidad['porcentaje_riesgo']);
    }

    public function test_agenda_de_manana_por_cobrador_y_alcance(): void
    {
        $cobrador = $this->usuario(['creditos.ver', 'creditos.cobrar']);
        DB::table('credito_configuracion')->update(['asesor_cobra' => false]);
        app(ClienteCreditoService::class)->asignarCartera($this->a->cliente, null, $cobrador, $this->admin);
        $this->hoy('2026-01-30');
        $this->cobrar($this->b, 20_000, 'rep-b3');   // adelanto de 200 a la cuota 1 de B

        $agenda = $this->reporte('agenda');   // sin fechas: mañana (31/01)
        $this->assertSame('2026-01-31', $agenda['periodo']['desde']);
        $this->assertSame(['cuotas' => 2, 'clientes' => 2, 'monto' => '1000.00'], $agenda['totales']);   // 600 + 400
        $dia = $agenda['dias'][0];
        $this->assertSame('2026-01-31', $dia['dia']);
        $porCobrador = collect($dia['cobradores'])->keyBy('cobrador');
        $this->assertSame('600.00', $porCobrador[$cobrador->name]['totales']['monto']);
        $this->assertSame('400.00', $porCobrador['Sin cobrador']['filas'][0]['pendiente']);
        $this->assertSame('1 de 10', $porCobrador[$cobrador->name]['filas'][0]['numero_cuota'] . ' de ' . $porCobrador[$cobrador->name]['filas'][0]['cuotas_total']);

        // El cobrador solo ve su agenda (y no necesita el permiso de reportes para ella).
        $suya = $this->reporte('agenda', [], $cobrador);
        $this->assertSame(['cuotas' => 1, 'clientes' => 1, 'monto' => '600.00'], $suya['totales']);
    }

    public function test_agenda_incluye_atrasados_con_su_mora(): void
    {
        $this->hoy('2026-02-05');

        $agenda = $this->reporte('agenda', ['incluir_atrasados' => 1]);

        $atrasados = $agenda['dias'][0];
        $this->assertNull($atrasados['dia']);
        $this->assertSame(2, $atrasados['totales']['cuotas']);   // cuota 1 de A y de B
        $this->assertSame('1400.00', $atrasados['totales']['monto']);   // 2 × (600 + 100 de mora)
        $this->assertTrue($atrasados['cobradores'][0]['filas'][0]['con_atraso']);
    }

    public function test_ingresos_por_mes_y_por_asesor(): void
    {
        $this->hoy('2026-01-31');
        $this->cobrar($this->b, 60_000, 'rep-b4');
        $this->hoy('2026-02-05');
        $this->cobrar($this->a, 70_000, 'rep-a1');   // cuota 1 + 100 de mora

        $ingresos = $this->reporte('ingresos', ['desde' => '2026-01-01', 'hasta' => '2026-02-05', 'agrupacion' => 'mes']);
        $meses = collect($ingresos['filas'])->keyBy('periodo');
        $this->assertSame(['10000.00', 2, '500.00', '100.00', '600.00'], [
            $meses['2026-01']['desembolsado'], $meses['2026-01']['creditos_entregados'], $meses['2026-01']['capital'], $meses['2026-01']['interes'], $meses['2026-01']['cobrado'],
        ]);
        $this->assertSame(['500.00', '100.00', '100.00', '700.00'], [
            $meses['2026-02']['capital'], $meses['2026-02']['interes'], $meses['2026-02']['mora'], $meses['2026-02']['cobrado'],
        ]);
        $this->assertSame('1300.00', $ingresos['totales']['cobrado']);

        $asesores = collect($this->reporte('asesores', ['desde' => '2026-01-01', 'hasta' => '2026-02-05'])['filas'])->keyBy('asesor');
        $this->assertSame(2, $asesores[$this->admin->name]['colocados']);
        $this->assertSame('10000.00', $asesores[$this->admin->name]['capital_colocado']);
        $this->assertSame('1300.00', $asesores[$this->admin->name]['cobrado']);
        $this->assertSame(2, $asesores['Sin asesor']['cartera_creditos']);   // nadie tiene asignados a estos clientes
    }

    public function test_castigados_y_recuperos(): void
    {
        $c = $this->activo($this->admin, null, $this->datos($this->cliente()->id, capital: 100_000, cuotas: 1, tope: TopeMoraTipo::SinTope));
        $this->hoy('2026-02-20');
        app(CastigoService::class)->castigar($c, TipoCastigo::Manual, 'Inubicable', $this->admin);
        $this->hoy('2026-02-25');
        $this->cobrar($c->fresh(), 30_000, 'rep-c1');

        $reporte = $this->reporte('castigados', ['desde' => '2026-02-01', 'hasta' => '2026-02-28']);

        $this->assertSame([$c->id], array_column($reporte['castigos'], 'credito_id'));
        $this->assertSame('manual', $reporte['castigos'][0]['tipo']);
        $this->assertSame('300.00', $reporte['total_recuperado']);
    }

    public function test_control_lista_anulaciones_y_alerta_por_umbral(): void
    {
        DB::table('credito_configuracion')->update(['umbral_alerta_anulaciones' => 1]);
        $this->hoy('2026-01-31');
        foreach (['rep-x1', 'rep-x2'] as $clave) {
            app(AnulacionPagoService::class)->anular($this->cobrar($this->b, 10_000, $clave), 'Error de digitación', $this->admin);
        }

        $control = $this->reporte('control', ['desde' => '2026-01-31', 'hasta' => '2026-01-31']);

        $anulaciones = array_values(array_filter($control['eventos'], static fn (array $e): bool => $e['accion'] === 'pago.anular'));
        $this->assertCount(2, $anulaciones);
        $this->assertSame('Error de digitación', $anulaciones[0]['motivo']);
        $this->assertSame([['usuario' => $this->admin->name, 'anulaciones' => 2]], $control['alertas']);
    }

    public function test_permisos_de_los_reportes(): void
    {
        $cobrador = $this->usuario(['creditos.ver', 'creditos.cobrar']);
        $cajeroSinTodo = $this->usuario(['creditos.ver', 'creditos.reportes']);

        foreach ([['ingresos', $cobrador], ['cartera', $cobrador], ['control', $cajeroSinTodo]] as [$reporte, $usuario]) {
            try {
                $this->reporte($reporte, [], $usuario);
                $this->fail("{$reporte} debía rechazarse");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertArrayHasKey('filas', $this->reporte('cartera', [], $cajeroSinTodo));
    }

    public function test_exportar_excel_y_pdf(): void
    {
        $this->hoy('2026-02-05');
        Auth::guard('api')->setUser($this->admin);
        $controller = app(ReporteCreditoController::class);

        $excel = $controller->excel(new Request(), 'cartera');
        $this->assertInstanceOf(BinaryFileResponse::class, $excel);
        $this->assertStringContainsString('cartera_de_creditos_2026-02-05.xlsx', (string) $excel->headers->get('content-disposition'));

        $pdf = $controller->pdf(new Request(['u' => $this->admin->id]), 'agenda');
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    public function test_foto_diaria_guarda_la_situacion_con_asesor_y_es_idempotente(): void
    {
        $asesor = $this->usuario(['creditos.crear', 'creditos.cobrar']);
        app(ClienteCreditoService::class)->asignarCartera($this->a->cliente, $asesor, null, $this->admin);
        $this->hoy('2026-02-05');

        $this->assertSame(2, app(FotoCarteraService::class)->tomar(Fecha::desdeTexto('2026-02-05')));
        $this->assertSame(2, app(FotoCarteraService::class)->tomar(Fecha::desdeTexto('2026-02-05')));

        $fila = CreditoCarteraDiaria::where('credito_id', $this->a->id)->firstOrFail();
        $this->assertSame(1, CreditoCarteraDiaria::where('credito_id', $this->a->id)->count());
        $this->assertSame([$asesor->id, '5000.00', '100.00', 5, '1-7'], [(int) $fila->asesor_id, (string) $fila->saldo_capital, (string) $fila->mora_pendiente, $fila->dias_atraso, $fila->rango_atraso]);
    }
}
