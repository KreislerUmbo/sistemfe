<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\Cash\CashRegister;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Módulo Caja — Fase 5 (plan-modulo-caja.md §9, index() original) + CRUD
// real (2026-08-20, cierra el gap documentado en CLAUDE.md: sin esto no
// había forma de crear la primera caja de un tenant real desde la UI —
// "Turno Activo" solo mostraba "No hay cajas disponibles para abrir" sin
// ningún botón para arreglarlo, el único camino era un comando de un solo
// uso hardcodeado al tenant 'sandbox'). Mismo patrón que BranchController:
// sin borrado real, destroy() desactiva.
class CashRegisterController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('active')) {
            $registers = CashRegister::where('is_active', $request->boolean('active'))
                ->when($request->filled('branch_id'), function ($query) use ($request) {
                    $query->where('branch_id', $request->query('branch_id'));
                })
                ->with('branch')
                ->orderBy('name')
                ->get();

            return response()->json([
                'cash_registers' => $registers,
            ]);
        }

        $search = $request->get('search');
        $registers = CashRegister::when($search, function ($query) use ($search) {
            $query->where('name', 'ilike', '%' . $search . '%');
        })
            ->with('branch')
            ->orderBy('name')
            ->paginate(15);

        return response()->json([
            'total' => $registers->total(),
            'paginate' => 15,
            'cash_registers' => $registers->items(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|integer|exists:branches,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
            'blind_close' => 'nullable|boolean',
            'default_opening_amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 405, 'message' => $validator->errors()->first()]);
        }

        $validado = $validator->validated();

        $register = CashRegister::create([
            'branch_id' => $validado['branch_id'],
            'name' => $validado['name'],
            'code' => $validado['code'] ?? null,
            'type' => $validado['type'] ?? 'fixed',
            'is_active' => $validado['is_active'] ?? true,
            'blind_close' => $validado['blind_close'] ?? null,
            'default_opening_amount' => $validado['default_opening_amount'] ?? 0,
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Caja creada correctamente',
            'cash_register' => $register->fresh('branch'),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $register = CashRegister::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|integer|exists:branches,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
            'blind_close' => 'nullable|boolean',
            'default_opening_amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 405, 'message' => $validator->errors()->first()]);
        }

        $validado = $validator->validated();

        $register->update([
            'branch_id' => $validado['branch_id'],
            'name' => $validado['name'],
            'code' => $validado['code'] ?? null,
            'type' => $validado['type'] ?? $register->type,
            'is_active' => $validado['is_active'] ?? $register->is_active,
            'blind_close' => $validado['blind_close'] ?? null,
            'default_opening_amount' => $validado['default_opening_amount'] ?? 0,
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Caja actualizada correctamente',
            'cash_register' => $register->fresh('branch'),
        ]);
    }

    /**
     * No borra la fila — desactiva. Sesiones/movimientos históricos que ya
     * referencian esta caja no se ven afectados; solo deja de aparecer como
     * disponible para abrir un turno nuevo. Mismo criterio que
     * BranchController::destroy()/PaymentMethodController.
     */
    public function destroy(string $id)
    {
        $register = CashRegister::findOrFail($id);
        $register->update(['is_active' => false]);

        return response()->json([
            'code' => 200,
            'message' => 'Caja desactivada correctamente',
        ]);
    }
}
