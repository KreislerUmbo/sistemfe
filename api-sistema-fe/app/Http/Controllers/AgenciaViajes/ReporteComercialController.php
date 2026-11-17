<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Exports\ReporteComercialExport;
use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\Client\CrmOrigenContacto;
use App\Models\User;
use App\Services\AgenciaViajes\ReporteComercial;
use App\Services\HoraPeru;
use App\Services\Tenancy\GiroActual;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\HttpException;

// T-062 (CRM C6b) — pantalla «Reportes comerciales» (VENTAS) y su Excel. Ruta con
// reportes_comerciales.ver; solo Agencia (404 en otro giro). Quien no tiene
// cotizaciones.ver_todas ve solo sus cotizaciones (Cotizacion::propias()). Los números salen de
// ReporteComercial.
class ReporteComercialController extends Controller
{
    use ValidaEnEspanol;

    private const MAX_MESES = 12;

    public function __construct(
        private readonly ReporteComercial $reporte,
        private readonly GiroActual $giro,
    ) {
    }

    // GET reportes-comerciales?desde=&hasta=&vendedor_id=&origen_contacto_id=
    // vendedor_id / origen_contacto_id aceptan 'sin' (sin vendedor / sin origen).
    public function index(Request $request): JsonResponse
    {
        $this->soloAgencia();
        $filtros = $this->filtros($request);

        return response()->json([
            'code' => 200,
            'filtros' => $filtros,
            'data' => $this->reporte->generar($filtros),
        ]);
    }

    public function export(Request $request)
    {
        $this->soloAgencia();
        $filtros = $this->filtros($request);
        $datos = $this->reporte->generar($filtros);

        $nombre = 'reportes_comerciales_del_' . CarbonImmutable::parse($filtros['desde'])->format('d-m-Y')
            . '_al_' . CarbonImmutable::parse($filtros['hasta'])->format('d-m-Y') . '.xlsx';

        return Excel::download(new ReporteComercialExport(
            $datos,
            $filtros,
            $this->textoFiltros($filtros),
            auth('api')->user()->name,
            CarbonImmutable::now(HoraPeru::ZONA),
        ), $nombre);
    }

    // GET reportes-comerciales/opciones — vendedores (los que tienen cotizaciones visibles para quien
    // pide) y orígenes (todos, también los desactivados: hay cotizaciones viejas con ellos).
    public function opciones(): JsonResponse
    {
        $this->soloAgencia();

        $vendedores = User::withTrashed()
            ->whereIn('id', Cotizacion::query()->propias()->delCotizador()->whereNotNull('vendedor_id')->select('vendedor_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'surname', 'state', 'deleted_at'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nombre' => trim($u->name . ' ' . $u->surname),
                'inactivo' => $u->deleted_at !== null || (int) $u->state === User::STATE_INACTIVO,
            ]);

        $origenes = CrmOrigenContacto::query()->orderBy('orden')->orderBy('nombre')
            ->get(['id', 'nombre', 'activo'])
            ->map(fn ($o) => ['id' => $o->id, 'nombre' => $o->nombre, 'inactivo' => ! $o->activo]);

        return response()->json(['code' => 200, 'vendedores' => $vendedores, 'origenes' => $origenes]);
    }

    /**
     * Rango por fecha de creación (días de Perú, ambos incluidos); por defecto, el mes en curso.
     * Máximo 12 meses.
     */
    private function filtros(Request $request): array
    {
        $validator = $this->validadorEs($request->all(), [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'vendedor_id' => ['nullable', 'regex:/^(sin|\d+)$/'],
            'origen_contacto_id' => ['nullable', 'regex:/^(sin|\d+)$/'],
        ], [
            'desde' => 'desde', 'hasta' => 'hasta', 'vendedor_id' => 'vendedor', 'origen_contacto_id' => 'origen',
        ]);
        $validator->setCustomMessages([
            'desde.date_format' => 'La fecha «Desde» no es válida.',
            'hasta.date_format' => 'La fecha «Hasta» no es válida.',
            'vendedor_id.regex' => 'El vendedor elegido no es válido.',
            'origen_contacto_id.regex' => 'El origen elegido no es válido.',
        ]);
        $validator->validate();

        $hoy = HoraPeru::hoy();
        $desde = $request->filled('desde') ? CarbonImmutable::parse($request->input('desde')) : CarbonImmutable::parse($hoy->format('Y-m-01'));
        $hasta = $request->filled('hasta') ? CarbonImmutable::parse($request->input('hasta')) : CarbonImmutable::parse($hoy->format('Y-m-d'));

        if ($hasta->lt($desde)) {
            throw ValidationException::withMessages(['hasta' => 'La fecha «Hasta» no puede ser anterior a «Desde».']);
        }
        if ($hasta->gte($desde->addMonthsNoOverflow(self::MAX_MESES))) {
            throw ValidationException::withMessages(['hasta' => 'El rango no puede superar los ' . self::MAX_MESES . ' meses.']);
        }

        $vendedor = $request->input('vendedor_id');
        $origen = $request->input('origen_contacto_id');

        return [
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'vendedor_id' => is_numeric($vendedor) ? (int) $vendedor : null,
            'sin_vendedor' => $vendedor === 'sin',
            'origen_contacto_id' => is_numeric($origen) ? (int) $origen : null,
            'sin_origen' => $origen === 'sin',
        ];
    }

    // Texto de los filtros para la cabecera del Excel.
    private function textoFiltros(array $filtros): string
    {
        $partes = [];
        if ($filtros['sin_vendedor']) {
            $partes[] = 'Vendedor: sin vendedor';
        } elseif ($filtros['vendedor_id']) {
            $u = User::withTrashed()->find($filtros['vendedor_id']);
            $partes[] = 'Vendedor: ' . ($u ? trim($u->name . ' ' . $u->surname) : '#' . $filtros['vendedor_id']);
        }
        if ($filtros['sin_origen']) {
            $partes[] = 'Origen: sin origen';
        } elseif ($filtros['origen_contacto_id']) {
            $partes[] = 'Origen: ' . (CrmOrigenContacto::find($filtros['origen_contacto_id'])?->nombre ?? '#' . $filtros['origen_contacto_id']);
        }

        return $partes ? implode(' · ', $partes) : 'Todos los vendedores y orígenes';
    }

    private function soloAgencia(): void
    {
        if (! $this->giro->es('agencia_viajes')) {
            throw new HttpException(404, 'No disponible para este giro.');
        }
    }
}
