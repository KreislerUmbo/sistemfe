<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\Cash\CashRegister;
use Illuminate\Http\Request;

// Módulo Caja — Fase 5 (plan-modulo-caja.md §9). Solo listado — el CRUD
// administrable completo de cajas sigue pendiente (CLAUDE.md), esto es
// exclusivamente para poblar el filtro de caja del historial (history.vue),
// mismo patrón ?active=1 de PaymentMethodController::index(). ?branch_id=
// permite acotar el selector de caja una vez elegida una sede.
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
}
