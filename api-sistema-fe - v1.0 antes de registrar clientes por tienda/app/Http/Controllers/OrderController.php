<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'nombres' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'email' => 'required|email',
            'telefono' => 'required|string',
            'dni' => 'nullable|string|max:11',
            'direccion' => 'nullable|string',
            'departamento' => 'nullable|string',
            'provincia' => 'nullable|string',
            'distrito' => 'nullable|string',
            'referencia' => 'nullable|string',
            'entrega_tipo' => 'required|in:despacho,tienda',
            'comprobante_tipo' => 'required|in:boleta,factura',
            'ruc' => 'nullable|required_if:comprobante_tipo,factura',
            'razon_social' => 'nullable|required_if:comprobante_tipo,factura',
            'metodo_pago' => 'required|string',
            'cupon' => 'nullable|string',
            'items' => 'required|array',
            'items.*.id' => 'required|exists:products,id',
            'items.*.cantidad' => 'required|integer|min:1',
            'items.*.precio' => 'required|numeric',
        ]);

        DB::beginTransaction();
        try {
            $order = Order::create([
                'user_id' => auth()->id(), // puede ser null si es invitado
                'nombres' => $request->nombres,
                'apellidos' => $request->apellidos,
                'email' => $request->email,
                'telefono' => $request->telefono,
                'dni' => $request->dni,
                'direccion' => $request->direccion,
                'departamento' => $request->departamento,
                'provincia' => $request->provincia,
                'distrito' => $request->distrito,
                'referencia' => $request->referencia,
                'entrega_tipo' => $request->entrega_tipo,
                'comprobante_tipo' => $request->comprobante_tipo,
                'ruc' => $request->ruc,
                'razon_social' => $request->razon_social,
                'metodo_pago' => $request->metodo_pago,
                'cupon' => $request->cupon,
                'subtotal' => $request->subtotal,
                'descuento' => $request->descuento ?? 0,
                'total' => $request->total,
                'estado' => 'pendiente',
            ]);

            foreach ($request->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['id'],
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $item['precio'],
                    'subtotal' => $item['precio'] * $item['cantidad'],
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => 'Pedido creado exitosamente',
                'order' => $order,
                'order_number' => $order->id,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al procesar el pedido', 'message' => $e->getMessage()], 500);
        }
    }
}