<?php

namespace App\Http\Controllers\Compras;

use App\Exports\CuentasPorPagarExport;
use App\Http\Controllers\Controller;
use App\Services\Compras\CuentasPorPagarService;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

// T-137 — cuentas por pagar. Solo lectura: pagar y aplicar van por las rutas de pagos (T-136).
// Sin Policy de fila: las compras son del negocio.
class CuentasPorPagarController extends Controller
{
    private const DIAS = 7;

    public function __construct(private CuentasPorPagarService $service) {}

    public function index(Request $request)
    {
        $filtros = $this->filtros($request);
        $resumen = $this->service->resumen($filtros['dias'], $filtros['search'], $filtros['solo_vencido']);

        $porPagina = (int) ($filtros['per_page'] ?? 15);
        $pagina = (int) ($filtros['page'] ?? 1);

        return response()->json([
            'hoy' => HoraPeru::hoyTexto(),
            'dias' => $filtros['dias'],
            'total' => count($resumen['grupos']),
            'paginate' => $porPagina,
            'grupos' => array_slice($resumen['grupos'], ($pagina - 1) * $porPagina, $porPagina),
            'totales' => $resumen['totales'],
        ]);
    }

    public function detalle(Request $request)
    {
        $datos = $request->validate([
            'clave' => 'required|string|max:40',
            'dias' => 'nullable|integer|between:1,365',
        ]);

        $grupo = $this->service->detalle($datos['clave'], (int) ($datos['dias'] ?? self::DIAS));
        abort_if($grupo === null, 404, 'Ese proveedor no tiene cuentas por pagar.');

        return response()->json(['grupo' => $grupo]);
    }

    public function excel(Request $request)
    {
        $filtros = $this->filtros($request);
        $resumen = $this->service->resumen($filtros['dias'], $filtros['search'], $filtros['solo_vencido']);

        return Excel::download(
            new CuentasPorPagarExport($resumen['grupos'], $resumen['totales'], $filtros['dias']),
            'cuentas-por-pagar-'.HoraPeru::hoyTexto().'.xlsx',
        );
    }

    private function filtros(Request $request): array
    {
        $datos = $request->validate([
            'dias' => 'nullable|integer|between:1,365',
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|between:1,100',
        ]);

        return [
            'dias' => (int) ($datos['dias'] ?? self::DIAS),
            'search' => $datos['search'] ?? null,
            'solo_vencido' => $request->boolean('solo_vencido'),
            'page' => $datos['page'] ?? null,
            'per_page' => $datos['per_page'] ?? null,
        ];
    }
}
