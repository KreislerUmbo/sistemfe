<?php

namespace App\Services\Roles;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * T-081 — rol del dueño de cada tenant. Tiene asignados de verdad todos los permisos del tenant
 * (cada tenant solo tiene los de su giro), menos los de PERMISOS_SOLO_SOPORTE. No pasa por
 * Gate::before (eso es solo del Super-Admin, la cuenta de soporte de UmboSystem): así en la
 * pantalla de Roles se ve qué tiene y una función nueva le llega con roles:sync-propietario.
 *
 * Es el único rol cuyo contenido administra el sistema entero, por eso es el único al que se le
 * hace syncPermissions(); los demás roles solo reciben givePermissionTo().
 */
class RolPropietario
{
    public const NOMBRE = 'Propietario';

    /**
     * Permisos que solo debe tener el soporte. Vacía: hoy lo exclusivo de soporte depende del rol
     * (hasRole('Super-Admin')) o del tenant de plataforma, no de un permiso. Si algún día un permiso
     * es solo de soporte, se agrega acá y el comando se lo quita al Propietario.
     */
    public const PERMISOS_SOLO_SOPORTE = [];

    /**
     * Crea el rol si falta y lo deja con todos los permisos del tenant menos los de soporte.
     * Idempotente. Corre dentro del tenant ya inicializado. $soloSoporte existe para los tests.
     *
     * @param  list<string>  $soloSoporte
     * @return array{agregados: list<string>, quitados: list<string>}
     */
    public static function sincronizar(array $soloSoporte = self::PERMISOS_SOLO_SOPORTE): array
    {
        $rol = Role::firstOrCreate(['name' => self::NOMBRE, 'guard_name' => 'api']);

        $deseados = Permission::where('guard_name', 'api')
            ->whereNotIn('name', $soloSoporte)
            ->get();
        $actuales = $rol->permissions()->pluck('name')->all();

        $agregados = array_values(array_diff($deseados->pluck('name')->all(), $actuales));
        $quitados = array_values(array_diff($actuales, $deseados->pluck('name')->all()));

        if ($agregados !== [] || $quitados !== []) {
            $rol->syncPermissions($deseados);
        }

        return ['agregados' => $agregados, 'quitados' => $quitados];
    }

    /**
     * ¿Queda algún otro Propietario activo (no Inactivo, no eliminado) además de $sin? Bloquea las
     * filas de los Propietarios: llamarla dentro de la transacción del cambio, para que dos
     * cambios a la vez no dejen el tenant sin ninguno.
     */
    public static function quedaOtroActivo(User $sin): bool
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', self::NOMBRE)->where('guard_name', 'api'))
            ->lockForUpdate()
            ->get(['id', 'state'])
            ->contains(fn (User $u): bool => $u->id !== $sin->id && (int) $u->state !== User::STATE_INACTIVO);
    }

    public static function es(?User $usuario): bool
    {
        return (bool) $usuario?->hasRole(self::NOMBRE);
    }
}
