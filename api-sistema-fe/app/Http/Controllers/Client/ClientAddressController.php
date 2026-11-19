<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ClientAddressRequest;
use App\Models\Client\Client;
use App\Models\Client\ClientAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// T-065 — otras direcciones del cliente (sucursales). Los permisos van en la ruta:
// listar/agregar los ven también las pantallas de emisión; editar/desactivar solo edit_client.
// No hay DELETE: una dirección usada por un comprobante no se borra nunca.
class ClientAddressController extends Controller
{
    public function index(Request $request, int $id): JsonResponse
    {
        $cliente = Client::findOrFail($id);

        $consulta = $cliente->direcciones()->orderByDesc('activo')->orderBy('nombre')->orderBy('id');
        if (! $request->boolean('todas')) {
            $consulta->where('activo', true);
        }

        return response()->json(['direcciones' => $consulta->get()]);
    }

    public function store(ClientAddressRequest $request, int $id): JsonResponse
    {
        $cliente = Client::findOrFail($id);

        $direccion = $cliente->direcciones()->create(
            $request->safe()->except('activo') + ['activo' => true]
        );

        return response()->json(['direccion' => $direccion], 201);
    }

    public function update(ClientAddressRequest $request, int $id): JsonResponse
    {
        $direccion = ClientAddress::findOrFail($id);
        $direccion->update($request->validated());

        return response()->json(['direccion' => $direccion->fresh()]);
    }
}
