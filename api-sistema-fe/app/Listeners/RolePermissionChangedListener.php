<?php

namespace App\Listeners;

use App\Models\RoleAuditLog;
use App\Models\User;
use App\Services\MenuResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Events\PermissionAttached;
use Spatie\Permission\Events\PermissionDetached;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Fase 1a (plan-modulo-menus-y-roles.md §9.6/§4.3/§9.7) — un solo listener
// para los 4 eventos de Spatie (recién habilitados, ver config/permission.php
// events_enabled) que hace las DOS cosas que el plan pide en el MISMO punto:
// (1) deja constancia en role_audit_logs, (2) invalida el caché de
// MenuResolver Y el caché interno de permisos de Spatie — un solo punto de
// invalidación, nunca dos mecanismos separados que puedan desincronizarse.
//
// Defensivo a propósito, en los dos pasos: `events_enabled=true` alcanza a
// CUALQUIER llamada existente a givePermissionTo()/assignRole()/
// syncPermissions() en todo el código (seeders, comandos de backfill, tests)
// — un fallo acá (ej. role_audit_logs todavía no migrada en algún tenant)
// nunca debe romper la asignación de permiso/rol en sí, que ya se aplicó
// antes de que el evento se dispare.
class RolePermissionChangedListener
{
    public function __construct(private MenuResolver $menuResolver)
    {
    }

    public function handlePermissionAttached(PermissionAttached $event): void
    {
        $this->procesar($event->model, $this->nombres($event->permissionsOrIds, Permission::class), 'permission_attached');
    }

    public function handlePermissionDetached(PermissionDetached $event): void
    {
        $this->procesar($event->model, $this->nombres($event->permissionsOrIds, Permission::class), 'permission_detached');
    }

    public function handleRoleAttached(RoleAttached $event): void
    {
        $this->procesar($event->model, $this->nombres($event->rolesOrIds, Role::class), 'role_attached');
    }

    public function handleRoleDetached(RoleDetached $event): void
    {
        $this->procesar($event->model, $this->nombres($event->rolesOrIds, Role::class), 'role_detached');
    }

    private function procesar(Model $model, array $nombres, string $accion): void
    {
        $esRol = $model instanceof Role;

        try {
            $actor = Auth::guard('api')->user();

            RoleAuditLog::create([
                'actor_user_id' => $actor?->id,
                'actor_email' => $actor?->email,
                'target_type' => $esRol ? 'role' : 'user',
                'target_id' => $model->getKey(),
                'target_label' => $esRol ? $model->name : ($model->email ?? null),
                'accion' => $accion,
                'detalle' => $nombres,
            ]);
        } catch (\Throwable $e) {
            Log::warning('role_audit_logs: fallo al registrar', [
                'error' => $e->getMessage(),
                'accion' => $accion,
            ]);
        }

        try {
            // PermissionRegistrar ya está namespaceado por tenant (ver
            // App\Listeners\NamespaceSpatiePermissionCache) — este forget
            // limpia el namespace del tenant activo, nunca el de otro.
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            if (! $esRol && $model instanceof User && tenancy()->initialized) {
                $this->menuResolver->invalidarUsuario((string) tenant('id'), $model->getKey());
            } elseif ($esRol) {
                // Cambio en un ROL: afecta a todos los usuarios que lo
                // tienen asignado — invalidar todo el namespace de menú del
                // tenant es más simple y robusto que enumerar usuarios uno
                // por uno (ver MenuResolver::invalidarTenantActivo()).
                $this->menuResolver->invalidarTenantActivo();
            }
        } catch (\Throwable $e) {
            Log::warning('invalidación de caché de menú/permisos: fallo', [
                'error' => $e->getMessage(),
                'accion' => $accion,
            ]);
        }
    }

    /**
     * $permissionsOrIds/$rolesOrIds puede llegar como id suelto, array de
     * ids, modelo, array de modelos, o Collection (docstring de Spatie) — se
     * normaliza a un array de nombres legibles para `detalle`.
     */
    private function nombres(mixed $valor, string $modeloClase): array
    {
        $items = $valor instanceof Collection ? $valor->all() : (is_array($valor) ? $valor : [$valor]);

        return collect($items)->map(function ($item) use ($modeloClase) {
            if (is_object($item) && isset($item->name)) {
                return $item->name;
            }

            $encontrado = $modeloClase::find($item);

            return $encontrado?->name ?? "id:{$item}";
        })->values()->all();
    }
}
