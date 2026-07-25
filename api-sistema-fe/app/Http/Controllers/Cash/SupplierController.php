<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\Cash\Supplier;
use Illuminate\Http\Request;

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Sin seed — tabla vacía, la
// llena el dueño del negocio.
class SupplierController extends Controller
{
    /**
     * Con ?active=1: lista plana, para el buscador de contraparte en egresos
     * manuales (plan §6, Fase 4 de caja) — no pagina.
     * Sin ?active: listado paginado para la pantalla de administración.
     */
    public function index(Request $request)
    {
        if ($request->has('active')) {
            $suppliers = Supplier::where('is_active', $request->boolean('active'))
                ->orderBy('name')
                ->get();

            return response()->json([
                'suppliers' => $suppliers,
            ]);
        }

        $search = $request->get('search');
        $suppliers = Supplier::when($search, function ($query) use ($search) {
            $query->where('name', 'ilike', '%' . $search . '%')
                ->orWhere('document', 'ilike', '%' . $search . '%');
        })
            ->orderBy('name')
            ->paginate(15);

        return response()->json([
            'total' => $suppliers->total(),
            'paginate' => 15,
            'suppliers' => $suppliers->items(),
        ]);
    }

    public function store(Request $request)
    {
        $supplier = Supplier::create($request->only('name', 'document', 'phone', 'is_active'));

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor creado correctamente',
            'supplier' => $supplier,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update($request->only('name', 'document', 'phone', 'is_active'));

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor actualizado correctamente',
            'supplier' => $supplier,
        ]);
    }

    /**
     * No borra la fila — desactiva (mismo criterio que PaymentMethodController).
     * Movimientos de caja históricos con este proveedor como contraparte no se
     * ven afectados (guardan un snapshot del nombre, no una referencia viva).
     */
    public function destroy(string $id)
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update(['is_active' => false]);

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor desactivado correctamente',
        ]);
    }
}
