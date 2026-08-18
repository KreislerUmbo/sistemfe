<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\Cash\Branch;
use Illuminate\Http\Request;

// Módulo Caja — Fase 1/5 (index, plan-modulo-caja.md §9) + CRUD real
// (2026-08-17, cierra el gap documentado en CLAUDE.md: sin esto no había
// forma de crear la sucursal necesaria para poder emitir comprobantes —
// serie_comprobantes.branch_id es NOT NULL). Mismo patrón que
// PaymentMethodController: sin borrado real, destroy() desactiva.
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

    public function store(Request $request)
    {
        if (! trim((string) $request->name)) {
            return response()->json(['code' => 405, 'message' => 'El nombre es obligatorio']);
        }

        if ($request->filled('code')) {
            $exists = Branch::whereRaw('LOWER(code) = ?', [strtolower(trim($request->code))])->first();
            if ($exists) {
                return response()->json(['code' => 405, 'message' => 'Ya existe una sucursal con ese código']);
            }
        }

        $branch = Branch::create($request->only('name', 'code', 'address', 'is_active'));

        return response()->json([
            'code' => 200,
            'message' => 'Sucursal creada correctamente',
            'branch' => $branch,
        ]);
    }

    public function update(Request $request, string $id)
    {
        if ($request->filled('code')) {
            $exists = Branch::where('id', '<>', $id)
                ->whereRaw('LOWER(code) = ?', [strtolower(trim($request->code))])
                ->first();
            if ($exists) {
                return response()->json(['code' => 405, 'message' => 'Ya existe una sucursal con ese código']);
            }
        }

        $branch = Branch::findOrFail($id);
        $branch->update($request->only('name', 'code', 'address', 'is_active'));

        return response()->json([
            'code' => 200,
            'message' => 'Sucursal actualizada correctamente',
            'branch' => $branch,
        ]);
    }

    /**
     * No borra la fila — desactiva. Ventas/series/cajas históricas que ya
     * referencian esta sucursal no se ven afectadas; solo se recomienda no
     * usarla para series nuevas (no hay guard duro, mismo criterio que
     * payment_methods).
     */
    public function destroy(string $id)
    {
        $branch = Branch::findOrFail($id);
        $branch->update(['is_active' => false]);

        return response()->json([
            'code' => 200,
            'message' => 'Sucursal desactivada correctamente',
        ]);
    }
}
