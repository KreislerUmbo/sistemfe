<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedido del checkout del portal, también como invitado (T-068). Ruta pública: precios, subtotal,
 * descuento y total que mande el navegador NO se leen (los calcula OrderController::store() con
 * el producto); por eso no se validan aquí.
 */
class PedidoPortalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombres' => ['required', 'string', 'max:255'],
            'apellidos' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'telefono' => ['required', 'string', 'max:25'],
            'dni' => ['nullable', 'string', 'max:11'],
            'direccion' => ['nullable', 'string', 'max:250'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'max:100'],
            'distrito' => ['nullable', 'string', 'max:100'],
            'referencia' => ['nullable', 'string', 'max:250'],
            'entrega_tipo' => ['required', 'in:despacho,tienda'],
            'comprobante_tipo' => ['required', 'in:boleta,factura'],
            'ruc' => ['nullable', 'required_if:comprobante_tipo,factura', 'string', 'max:11'],
            'razon_social' => ['nullable', 'required_if:comprobante_tipo,factura', 'string', 'max:255'],
            'metodo_pago' => ['required', 'string', 'max:50'],
            'cupon' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
