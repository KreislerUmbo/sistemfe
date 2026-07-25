<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\Cash\Branch;
use Illuminate\Http\Request;

// Módulo Caja — Fase 5 (plan-modulo-caja.md §9). Solo listado — el CRUD
// administrable completo de sedes sigue pendiente (CLAUDE.md), esto es
// exclusivamente para poblar el filtro de sede del historial de caja
// (history.vue), mismo patrón ?active=1 de PaymentMethodController::index().
class BranchController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('active')) {
            $branches = Branch::where('is_active', $request->boolean('active'))
                ->orderBy('name')
                ->get();

            return response()->json([
                'branches' => $branches,
            ]);
        }

        $search = $request->get('search');
        $branches = Branch::when($search, function ($query) use ($search) {
            $query->where('name', 'ilike', '%' . $search . '%');
        })
            ->orderBy('name')
            ->paginate(15);

        return response()->json([
            'total' => $branches->total(),
            'paginate' => 15,
            'branches' => $branches->items(),
        ]);
    }
}
