<?php

namespace App\Http\Controllers;

use App\Http\Requests\Portal\PedidoPortalRequest;
use App\Models\Client\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    // T-068: ruta pública (también invitados). Precios, subtotal, descuento y
    // total se calculan aquí con el producto; lo que mande el navegador no se
    // lee (antes se guardaba tal cual: cualquiera podía comprar al precio que
    // quisiera). Se valida ANTES de crear al cliente invitado.
    public function store(PedidoPortalRequest $request)
    {
        $datos = $request->validated();

        // Mismo filtro que el catálogo del portal (Portal\ProductController).
        $productos = Product::where('state', 1)
            ->whereIn('id', collect($datos['items'])->pluck('id'))
            ->get()
            ->keyBy('id');

        $lineas = [];
        $subtotalCentavos = 0;
        foreach ($datos['items'] as $i => $item) {
            $producto = $productos->get($item['id']);
            if (! $producto) {
                throw ValidationException::withMessages([
                    "items.$i.id" => 'Uno de los productos ya no está disponible. Actualiza tu carrito.',
                ]);
            }

            $precioCentavos = (int) round((float) $producto->price_general * 100);
            $lineaCentavos = $precioCentavos * (int) $item['cantidad'];
            $subtotalCentavos += $lineaCentavos;

            $lineas[] = [
                'product_id' => $producto->id,
                'cantidad' => (int) $item['cantidad'],
                'precio_unitario' => $precioCentavos / 100,
                'subtotal' => $lineaCentavos / 100,
            ];
        }

        // No hay cupones en el servidor: el código se guarda como referencia
        // y no descuenta nada (el "TECH20" de la plantilla vivía solo en la
        // pantalla).
        $descuentoCentavos = 0;
        $totalCentavos = $subtotalCentavos - $descuentoCentavos;

        DB::beginTransaction();
        try {
            $client = $this->clienteDelPedido($datos);

            $order = Order::create([
                'client_id' => $client->id,
                'nombres' => $datos['nombres'],
                'apellidos' => $datos['apellidos'],
                'email' => $datos['email'],
                'telefono' => $datos['telefono'],
                'dni' => $datos['dni'] ?? null,
                'direccion' => $datos['direccion'] ?? null,
                'departamento' => $datos['departamento'] ?? null,
                'provincia' => $datos['provincia'] ?? null,
                'distrito' => $datos['distrito'] ?? null,
                'referencia' => $datos['referencia'] ?? null,
                'entrega_tipo' => $datos['entrega_tipo'],
                'comprobante_tipo' => $datos['comprobante_tipo'],
                'ruc' => $datos['ruc'] ?? null,
                'razon_social' => $datos['razon_social'] ?? null,
                'metodo_pago' => $datos['metodo_pago'],
                'cupon' => $datos['cupon'] ?? null,
                'subtotal' => $subtotalCentavos / 100,
                'descuento' => $descuentoCentavos / 100,
                'total' => $totalCentavos / 100,
                'estado' => 'pendiente',
            ]);

            foreach ($lineas as $linea) {
                OrderItem::create(['order_id' => $order->id] + $linea);
            }

            DB::commit();

            return response()->json([
                'success' => 'Pedido creado exitosamente',
                'order' => $order,
                'order_number' => $order->id,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Pedido del portal: ' . $e->getMessage(), ['exception' => $e]);

            // Sin el mensaje interno: la ruta es pública.
            return response()->json(['error' => 'Error al procesar el pedido'], 500);
        }
    }

    // El cliente logueado, o el que ya existe con ese correo, o uno nuevo
    // (invitado, sin contraseña).
    private function clienteDelPedido(array $datos): Client
    {
        if (auth('client')->check()) {
            return auth('client')->user();
        }

        $client = Client::where('email', $datos['email'])->first();
        if ($client) {
            return $client;
        }

        // T-068: el portal no manda tipo de documento y la columna es
        // obligatoria; antes el alta de un invitado fallaba con 500. Con DNI,
        // primero el cliente que ya lo tiene (índice único de documento).
        $dni = $datos['dni'] ?? null;
        if ($dni) {
            $client = Client::where('type_document', 'DNI')->where('n_document', $dni)->first();
            if ($client) {
                return $client;
            }
        }

        return Client::create([
            'name' => $datos['nombres'],
            'surname' => $datos['apellidos'],
            'full_name' => $datos['nombres'] . ' ' . $datos['apellidos'],
            'email' => $datos['email'],
            'phone' => $datos['telefono'],
            'type_document' => $dni ? 'DNI' : 'SND',
            'n_document' => $dni ?: '00000000',
            'address' => $datos['direccion'] ?? null,
            'distrito' => $datos['distrito'] ?? null,
            'provincia' => $datos['provincia'] ?? null,
            'region' => $datos['departamento'] ?? null,
            'type_client' => 1,
            'state' => 1,
        ]);
    }
}
