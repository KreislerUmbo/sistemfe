<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\Cash\CashConcept;
use Illuminate\Http\Request;

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3).
class CashConceptController extends Controller
{
    /**
     * Con ?active=1 (y opcionalmente ?direction=in|out): lista plana, para el
     * selector de concepto en ingresos/egresos manuales (Fase 4 de caja) — no
     * pagina. Sin ?active: listado paginado para la pantalla de administración.
     */
    public function index(Request $request)
    {
        if ($request->has('active')) {
            $cashConcepts = CashConcept::where('is_active', $request->boolean('active'))
                ->when($request->get('direction'), function ($query, $direction) {
                    $query->where('direction', $direction);
                })
                ->orderBy('name')
                ->get();

            return response()->json([
                'cash_concepts' => $cashConcepts,
            ]);
        }

        $search = $request->get('search');
        $cashConcepts = CashConcept::when($search, function ($query) use ($search) {
            $query->where('name', 'ilike', '%' . $search . '%');
        })
            ->orderBy('name')
            ->paginate(15);

        return response()->json([
            'total' => $cashConcepts->total(),
            'paginate' => 15,
            'cash_concepts' => $cashConcepts->items(),
        ]);
    }

    // 'direction' no tiene CHECK a nivel de Postgres a propósito (ver
    // create_cash_concepts_table.php) — column es un string plano, así que
    // esta validación es la única barrera real contra un valor fuera de
    // 'in'/'out'. No delegar esto al frontend.
    private function validarDireccion(Request $request): array
    {
        return $request->validate([
            'name'      => 'required|string|max:255',
            'direction' => 'required|string|in:in,out',
            'is_active' => 'nullable|boolean',
        ]);
    }

    public function store(Request $request)
    {
        $cashConcept = CashConcept::create($this->validarDireccion($request));

        return response()->json([
            'code' => 200,
            'message' => 'Concepto creado correctamente',
            'cash_concept' => $cashConcept,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $cashConcept = CashConcept::findOrFail($id);
        $cashConcept->update($this->validarDireccion($request));

        return response()->json([
            'code' => 200,
            'message' => 'Concepto actualizado correctamente',
            'cash_concept' => $cashConcept,
        ]);
    }

    /**
     * No borra la fila — desactiva (mismo criterio que PaymentMethodController).
     */
    public function destroy(string $id)
    {
        $cashConcept = CashConcept::findOrFail($id);
        $cashConcept->update(['is_active' => false]);

        return response()->json([
            'code' => 200,
            'message' => 'Concepto desactivado correctamente',
        ]);
    }
}
