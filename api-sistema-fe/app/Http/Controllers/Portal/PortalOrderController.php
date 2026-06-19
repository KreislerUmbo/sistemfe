<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Order;

class PortalOrderController extends Controller
{


    public function clientOrders()
    {
        $client = auth('client')->id();
        // Lista paginada de pedidos con relaciones
        $orders = Order::where('client_id', $client)
            ->with('items.product')
            ->orderBy('id', 'desc')
            ->paginate(10);


        // Consulta principal con agregaciones
        $stats = Order::selectRaw('
            COUNT(*) as total_pedidos,
            SUM(total) as total_gastado,
            SUM(CASE WHEN estado = \'pendiente\' THEN 1 ELSE 0 END) as pendientes,
            SUM(CASE WHEN estado = \'entregado\' THEN 1 ELSE 0 END) as entregados
        ')
            ->where('client_id', $client)
            ->first();


        return response()->json([
            'success' => true,
            'orders' => $orders,
            'total_pedidos' => $stats->total_pedidos ?? 0,
            'total_gastado' => $stats->total_gastado ?? 0,
            'pendientes' => $stats->pendientes ?? 0,
            'entregados' => $stats->entregados ?? 0,
        ]);
    }


    public function show($id) //esta ruta es para mostrar los detalles de un pedido
    {
        $client = auth('client')->id();

        $order = Order::with(['items.product'])
            ->where('client_id', $client)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'order' => $order
        ]);
    }
}
