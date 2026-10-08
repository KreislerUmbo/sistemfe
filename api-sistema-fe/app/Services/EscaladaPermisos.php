<?php

namespace App\Services;

/**
 * Regla única anti-escalación de privilegios: nadie puede otorgar (a un usuario o a
 * un rol) ni tomar el control de un usuario con permisos que él mismo no tiene.
 *
 * Auditoría de seguridad 08-oct-2026, hallazgo 5: UserController::permisosDirectos()
 * ya aplicaba esto, pero asignar un rol (Usuarios) o editar los permisos de un rol
 * (Roles) no — quien tenía edit_user podía asignarse "Administrador", y quien tenía
 * edit_role podía darle a su propio rol cualquier permiso.
 *
 * Usa $actor->can() (no getAllPermissions()) porque Super-Admin no tiene permisos
 * explícitos: los obtiene vía Gate::before(), y can() sí pasa por ese Gate.
 */
final class EscaladaPermisos
{
    /**
     * Permisos de la lista que quien opera NO tiene.
     *
     * @param  iterable<string>  $permisos
     * @return list<string>
     */
    public static function faltantes(iterable $permisos): array
    {
        $actor = auth('api')->user();
        $faltantes = [];

        foreach ($permisos as $permiso) {
            if (! $actor?->can($permiso)) {
                $faltantes[] = $permiso;
            }
        }

        return array_values(array_unique($faltantes));
    }

    public static function mensaje(string $accion, array $faltantes): string
    {
        $lista = implode(', ', array_slice($faltantes, 0, 8)) . (count($faltantes) > 8 ? '…' : '');

        return "No puedes {$accion}: incluye permisos que tú no tienes ({$lista}). Pide a un administrador que lo haga.";
    }
}
