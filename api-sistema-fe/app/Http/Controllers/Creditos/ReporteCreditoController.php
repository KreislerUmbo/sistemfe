<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Exports\Creditos\ReporteCreditoExport;
use App\Models\Company;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Reloj;
use App\Services\Creditos\Reportes\AgendaCobranzaService;
use App\Services\Creditos\Reportes\ControlAuditoriaService;
use App\Services\Creditos\Reportes\Periodo;
use App\Services\Creditos\Reportes\ReportesCarteraService;
use App\Services\Creditos\Reportes\ReportesFinancierosService;
use App\Services\Creditos\Reportes\TablasReporte;
use App\Services\StorageUrl;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Panel y reportes del módulo Créditos (04d). Cada reporte respeta el alcance de cartera; los
 * financieros y de control exigen además ver toda la cartera. PDF por URL firmada (lleva el
 * usuario, como los documentos del crédito); Excel por descarga con la sesión.
 */
class ReporteCreditoController extends ControllerCreditos
{
    public const REPORTES = ['agenda', 'cartera', 'morosidad', 'ingresos', 'asesores', 'castigados', 'control'];
    /** Reportes que muestran dinero de toda la empresa: solo con creditos.ver_todos. */
    private const SOLO_VER_TODOS = ['ingresos', 'asesores', 'castigados', 'control'];
    private const MINUTOS_URL = 10;

    public function __construct(
        private readonly ReportesCarteraService $cartera,
        private readonly AgendaCobranzaService $agenda,
        private readonly ReportesFinancierosService $financieros,
        private readonly ControlAuditoriaService $control,
        private readonly Reloj $reloj,
    ) {
    }

    public function panel(): JsonResponse
    {
        return response()->json($this->cartera->panel($this->usuario()));
    }

    public function show(Request $request, string $reporte): JsonResponse
    {
        return response()->json($this->datos($reporte, $request, $this->usuario()));
    }

    /** URL firmada del PDF (se abre en otra pestaña, sin el token). */
    public function pdfUrl(Request $request, string $reporte): JsonResponse
    {
        $usuario = $this->usuario();
        $this->datos($reporte, $request, $usuario);   // valida filtros y permisos antes de firmar

        return response()->json(['url' => URL::temporarySignedRoute('creditos.reporte.pdf', now()->addMinutes(self::MINUTOS_URL), [
            ...array_filter($request->only(['desde', 'hasta', 'agrupacion', 'asesor_id', 'cobrador_id', 'rango', 'estado', 'distrito', 'accion', 'usuario_id', 'incluir_atrasados']), static fn ($v): bool => $v !== null && $v !== ''),
            'reporte' => $reporte,
            'u' => $usuario->id,
            'generado_en' => now()->toIso8601String(),
        ])]);
    }

    public function pdf(Request $request, string $reporte): Response
    {
        $usuario = User::findOrFail((int) $request->query('u'));
        $tabla = TablasReporte::de($reporte, $this->datos($reporte, $request, $usuario));
        $empresa = Company::first();

        return Pdf::loadView('pdf.creditos.reporte_a4', [
            'tabla' => $tabla,
            'empresa' => $empresa,
            'logo' => StorageUrl::resolveParaPdf($empresa?->logo_horizontal),
            'generadoPor' => $usuario->name,
            'generadoEn' => \Carbon\CarbonImmutable::parse((string) $request->query('generado_en', now()->toIso8601String()))
                ->setTimezone(Reloj::ZONA)->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape')->stream($this->nombreArchivo($reporte, $request, 'pdf'));
    }

    public function excel(Request $request, string $reporte): BinaryFileResponse
    {
        $usuario = $this->usuario();
        $tabla = TablasReporte::de($reporte, $this->datos($reporte, $request, $usuario));

        return Excel::download(
            new ReporteCreditoExport($tabla, $usuario->name, $this->reloj->ahora()->format('d/m/Y H:i')),
            $this->nombreArchivo($reporte, $request, 'xlsx'),
        );
    }

    /** @return array<string, mixed> */
    private function datos(string $reporte, Request $request, User $usuario): array
    {
        if (! in_array($reporte, self::REPORTES, true)) {
            throw new HttpException(404, 'Reporte no encontrado.');
        }
        if (in_array($reporte, self::SOLO_VER_TODOS, true) && ! $usuario->can(AlcanceCartera::PERMISO_VER_TODOS)) {
            throw new HttpException(403, 'Este reporte requiere ver toda la cartera.');
        }
        // La agenda la usa también el cobrador; el resto, quien tiene el permiso de reportes.
        if ($reporte !== 'agenda' && ! $usuario->can('creditos.reportes')) {
            throw new HttpException(403, 'No tienes permiso para ver reportes.');
        }

        $f = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'agrupacion' => ['nullable', Rule::in(ReportesFinancierosService::AGRUPACIONES)],
            'asesor_id' => ['nullable', 'integer', 'min:0'],
            'cobrador_id' => ['nullable', 'integer', 'min:0'],
            'rango' => ['nullable', Rule::in(['al_dia', '1-7', '8-15', '16-30', '31-60', '60+'])],
            'estado' => ['nullable', Rule::in(['activo', 'castigado'])],
            'distrito' => ['nullable', 'string', 'max:80'],
            'accion' => ['nullable', 'string', 'max:40'],
            'usuario_id' => ['nullable', 'integer'],
            'incluir_atrasados' => ['nullable', 'boolean'],
        ]);
        $entero = static fn (?string $clave) => isset($f[$clave]) ? (int) $f[$clave] : null;

        return match ($reporte) {
            'agenda' => $this->agenda->agenda($usuario, $this->periodo($f, 1, AgendaCobranzaService::MAXIMO_DIAS), $request->boolean('incluir_atrasados'), [
                'cobrador_id' => $entero('cobrador_id'),
                'distrito' => $f['distrito'] ?? null,
            ]),
            'cartera' => $this->cartera->cartera($usuario, ['asesor_id' => $entero('asesor_id'), 'rango' => $f['rango'] ?? null, 'estado' => $f['estado'] ?? null]),
            'morosidad' => $this->cartera->morosidad($usuario),
            'ingresos' => $this->financieros->ingresos($this->periodo($f, 0), $f['agrupacion'] ?? 'dia'),
            'asesores' => $this->financieros->porAsesor($this->periodo($f, 0), $usuario, $this->cartera),
            'castigados' => $this->financieros->castigados($this->periodo($f, 0)),
            'control' => $this->control->control($this->periodo($f, 0), $f['accion'] ?? null, $entero('usuario_id')),
        };
    }

    /**
     * Período del filtro; sin fechas: desde hoy + $inicio días, un solo día (agenda: mañana) o el
     * mes en curso hasta hoy (resto).
     */
    private function periodo(array $f, int $inicio, int $maximo = Periodo::MAXIMO_DIAS): Periodo
    {
        $hoy = $this->reloj->hoy();
        if (empty($f['desde']) && empty($f['hasta'])) {
            return $inicio > 0 ? Periodo::dia($hoy->sumarDias($inicio)) : Periodo::entre($hoy->conDia(1), $hoy);
        }
        $desde = Fecha::desdeTexto($f['desde'] ?? $f['hasta']);
        $hasta = Fecha::desdeTexto($f['hasta'] ?? $f['desde']);

        return Periodo::entre($desde, $hasta, $maximo);
    }

    private function nombreArchivo(string $reporte, Request $request, string $extension): string
    {
        $base = str_replace(' ', '_', mb_strtolower(TablasReporte::TITULOS[$reporte] ?? $reporte));
        $base = strtr($base, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        $fechas = array_filter([$request->query('desde'), $request->query('hasta')]);
        $sufijo = $fechas === [] ? $this->reloj->hoy()->aTexto() : implode('_al_', array_unique($fechas));

        return "{$base}_{$sufijo}.{$extension}";
    }
}
