<?php

namespace Tests\Feature\AgenciaViajes\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Auditoría reserva + facturación (23-oct-2026): cada acción sobre una
// reserva pasa por ReservaPolicy — solo el vendedor de la cotización o
// alguien con reservas.ver_todas. Estos tests prueban otras reglas (fechas,
// facturación, salidas…), así que actúan como alguien que ve todas. La
// regla de dueño se prueba aparte en ReservaPolicyAccionesTest.
trait ConAccesoATodasLasReservas
{
    // Sin usuario: crea uno. Con usuario: le suma el permiso. En los dos
    // casos queda como el usuario de la petición (guard api).
    protected function verTodasLasReservas(?User $user = null): User
    {
        $user ??= User::factory()->create([
            // users.role_id tiene FK a roles y no todos los tests crean uno.
            'role_id' => Role::create(['name' => 'rol-ver-todas-'.uniqid(), 'guard_name' => 'api'])->id,
        ]);
        // cotizaciones.ver_todas: aceptar una alternativa pregunta a CotizacionPolicy.
        foreach (['reservas.ver_todas', 'cotizaciones.ver_todas'] as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }
        Auth::guard('api')->setUser($user->fresh());

        return $user;
    }
}
