<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\Client\CrmAccion;
use App\Services\Crm\BandejaPendientes;
use App\Services\HoraPeru;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// T-051 (CRM C2) — la bandeja de pendientes de la pantalla Seguimiento (y de "De todos" en
// Inicio). Sin filtro, las del usuario; las de otro o "de todos", solo con cotizaciones.ver_todas.
class CrmPendientesController extends Controller
{
    use ValidaEnEspanol;

    public function __construct(private readonly BandejaPendientes $bandeja)
    {
    }

    // GET crm/pendientes?ambito=mios|todos&responsable_id=&tipo=&hasta=
    public function index(Request $request): JsonResponse
    {
        $validator = $this->validadorEs($request->all(), [
            'ambito' => 'nullable|in:mios,todos',
            'responsable_id' => 'nullable|integer',
            'tipo' => ['nullable', Rule::in(array_keys(CrmAccion::TIPOS))],
            'hasta' => 'nullable|date_format:Y-m-d',
        ], ['ambito' => 'ámbito', 'responsable_id' => 'responsable', 'tipo' => 'tipo', 'hasta' => 'hasta']);
        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $datos = $validator->validated();
        $usuario = auth('api')->user();
        $verTodos = $usuario->can('cotizaciones.ver_todas');

        $responsableId = ($datos['ambito'] ?? 'mios') === 'todos' ? ($datos['responsable_id'] ?? null) : $usuario->id;
        if ($responsableId === null || (int) $responsableId !== (int) $usuario->id) {
            abort_unless($verTodos, 403, 'Solo puedes ver tus propios pendientes.');
        }

        $hoy = CarbonImmutable::parse(HoraPeru::hoyTexto());
        $hasta = isset($datos['hasta'])
            ? CarbonImmutable::parse($datos['hasta'])
            : $hoy->addDays(BandejaPendientes::DIAS_PROXIMOS);

        return response()->json([
            'hoy' => $hoy->toDateString(),
            'hasta' => $hasta->toDateString(),
            'puede_ver_todos' => $verTodos,
            'filas' => $this->bandeja->filas($usuario, $hoy, $hasta, [
                'responsable_id' => $responsableId,
                'tipo' => $datos['tipo'] ?? null,
            ]),
        ]);
    }
}
