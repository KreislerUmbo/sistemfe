<?php

namespace App\Services\Client;

use App\Models\Client\ClientAddress;
use Illuminate\Validation\ValidationException;

// T-065 — valida la "dirección del comprobante" que llega de las pantallas de emisión:
// debe existir, ser del cliente del comprobante y estar activa. Sin esto, un id ajeno
// imprimiría la dirección de otro cliente en un comprobante fiscal.
class DireccionDelComprobante
{
    /**
     * @param  int|null  $yaElegidaId  la dirección que el comprobante ya tenía (al editarlo sin
     *                                 cambiar de cliente): se acepta aunque después se haya
     *                                 desactivado, para que corregir otra cosa no quede bloqueado.
     * @return int|null el id validado, o null si no se eligió ninguna
     */
    public static function validar(mixed $addressId, ?int $clientId, ?int $yaElegidaId = null): ?int
    {
        if ($addressId === null || $addressId === '') {
            return null;
        }

        $direccion = ClientAddress::find((int) $addressId);

        if (! $direccion || (int) $direccion->client_id !== (int) $clientId) {
            throw ValidationException::withMessages([
                'client_address_id' => 'La dirección elegida no pertenece al cliente del comprobante.',
            ]);
        }
        if (! $direccion->activo && $direccion->id !== $yaElegidaId) {
            throw ValidationException::withMessages([
                'client_address_id' => 'La dirección elegida está desactivada. Elige otra o actívala en la ficha del cliente.',
            ]);
        }

        return $direccion->id;
    }
}
