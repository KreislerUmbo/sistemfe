<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\Cash\PaymentMethod;
use Illuminate\Http\Request;

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3).
class PaymentMethodController extends Controller
{
    /**
     * Con ?active=1: lista plana ordenada por sort_order, para el picker de
     * register.vue/edit.vue (Paso 7) — no pagina, el catálogo es chico.
     * Sin ?active: listado paginado para la pantalla de administración.
     */
    public function index(Request $request)
    {
        if ($request->has('active')) {
            $paymentMethods = PaymentMethod::where('is_active', $request->boolean('active'))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return response()->json([
                'payment_methods' => $paymentMethods,
            ]);
        }

        $search = $request->get('search');
        $paymentMethods = PaymentMethod::when($search, function ($query) use ($search) {
            $query->where('name', 'ilike', '%' . $search . '%');
        })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(15);

        return response()->json([
            'total' => $paymentMethods->total(),
            'paginate' => 15,
            'payment_methods' => $paymentMethods->items(),
        ]);
    }

    public function store(Request $request)
    {
        $exists = PaymentMethod::whereRaw('LOWER(code) = ?', [strtolower(trim($request->code))])->first();
        if ($exists) {
            return response()->json([
                'code' => 405,
                'message' => 'Ya existe un método de pago con ese código',
            ]);
        }

        $paymentMethod = PaymentMethod::create($request->only('code', 'name', 'is_active', 'sort_order'));

        return response()->json([
            'code' => 200,
            'message' => 'Método de pago creado correctamente',
            'payment_method' => $paymentMethod,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $exists = PaymentMethod::where('id', '<>', $id)
            ->whereRaw('LOWER(code) = ?', [strtolower(trim($request->code))])
            ->first();
        if ($exists) {
            return response()->json([
                'code' => 405,
                'message' => 'Ya existe un método de pago con ese código',
            ]);
        }

        $paymentMethod = PaymentMethod::findOrFail($id);
        $paymentMethod->update($request->only('code', 'name', 'is_active', 'sort_order'));

        return response()->json([
            'code' => 200,
            'message' => 'Método de pago actualizado correctamente',
            'payment_method' => $paymentMethod,
        ]);
    }

    /**
     * No borra la fila (plan §3) — desactiva. Ventas históricas que ya usaron
     * este método siguen mostrándolo igual; solo se bloquea su uso en ventas
     * nuevas (ver guard en SaleController::store()).
     */
    public function destroy(string $id)
    {
        $paymentMethod = PaymentMethod::findOrFail($id);
        $paymentMethod->update(['is_active' => false]);

        return response()->json([
            'code' => 200,
            'message' => 'Método de pago desactivado correctamente',
        ]);
    }
}
