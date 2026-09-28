<?php

namespace App\Services;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fase 0c, Partes 2/3 (plan-modulo-menus-y-roles.md §9.1, Bucket B) — activación
 * del gate real sobre las 31 rutas operativas que estaban en modo sombra
 * (docs/planning/claude/shadow-mode-bucket-b-fase0c.md).
 *
 * Hasta hoy esas rutas solo exigían auth:api: CUALQUIER usuario autenticado
 * podía, por ejemplo, enviar a SUNAT o dar de alta un cliente desde la venta.
 * Pasarlas a permission:X de golpe le quitaría a roles reales capacidades que
 * usan a diario sin haber tenido nunca el permiso explícito (ej. el Cajero
 * crea clientes al vuelo con ClientFormQuick desde sale/register.vue, pero no
 * tiene register_client). Por eso este servicio, antes de activar el gate:
 *
 * 1. Crea (idempotente) los permisos nuevos del mapeo de Bucket B.
 * 2. Otorga cada permiso DERIVADO a todo rol/usuario (permiso directo, Fase 2d)
 *    que ya tenga alguno de sus permisos "fuente" — es decir, a quien hoy ya
 *    ejecuta ese flujo de negocio. Ej: quien registra ventas también envía a
 *    SUNAT y crea el cliente de la venta.
 *
 * Nunca quita nada ni usa syncPermissions() — mismo criterio que
 * permisos:backfill-gate-bucket-a / roles:sync-permisos-nuevos: no pisar
 * personalizaciones manuales de un tenant real. Lo que NO está en DERIVACIONES
 * (ej. productos/categorías, eliminar clientes, notas) queda bloqueado para
 * quien no tenga el permiso: ese es justamente el objetivo del gate.
 */
class GateBucketBPermisos
{
    /** Permisos que no existían en ningún tenant antes de Fase 0c. */
    public const PERMISOS_NUEVOS = [
        'enviar_sunat',
        'register_sale_detail', 'edit_sale_detail', 'delete_sale_detail',
        'register_sale_payment', 'edit_sale_payment', 'delete_sale_payment',
        'registrar-cronograma-credito', 'editar-cuota-credito', 'registrar-pago-credito',
    ];

    // Roles/permisos cuyas pantallas crean o editan un cliente al vuelo
    // (ClientFormQuick): sale/register.vue, sale/edit.vue, cotizador
    // nueva/editar, reservas/detalle, venta-directa.
    private const FUENTES_CLIENTE_AL_VUELO = [
        'register_sale', 'edit_sale',
        'cotizaciones.crear', 'cotizaciones.editar',
        'reservas.crear', 'reservas.editar',
    ];

    /** permiso destino => permisos fuente (basta con tener uno). */
    public const DERIVACIONES = [
        // enviarSunat lo llaman sale/index.vue (ventas) y advances/show.vue (adelantos).
        'enviar_sunat' => ['register_sale', 'edit_sale', 'register_advance'],
        'register_client' => self::FUENTES_CLIENTE_AL_VUELO,
        'edit_client' => self::FUENTES_CLIENTE_AL_VUELO,
        // Endpoints sin caller real en el frontend (Fase 0b) — paridad con la venta.
        'register_sale_detail' => ['register_sale'],
        'edit_sale_detail' => ['edit_sale'],
        'delete_sale_detail' => ['delete_sale'],
        'register_sale_payment' => ['register_sale'],
        'edit_sale_payment' => ['edit_sale'],
        'delete_sale_payment' => ['delete_sale'],
        // Cronograma de cuotas: se arma al registrar/editar una venta a crédito.
        'registrar-cronograma-credito' => ['register_sale', 'edit_sale'],
        'editar-cuota-credito' => ['edit_sale'],
        // Cobro de cuotas (credit/client-detail.vue): quien vende o ya gestiona pagos de crédito.
        'registrar-pago-credito' => ['register_sale', 'anular-pago-credito'],
    ];

    /**
     * Debe correr dentro del contexto de un tenant ya inicializado.
     *
     * @return array{creados: string[], otorgados: array<int, array{tipo: string, nombre: string, permiso: string}>}
     */
    public function aplicar(bool $dryRun = false): array
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // También los destinos reutilizados (register_client/edit_client): existen
        // en todo tenant sembrado con PermissionsDemoSeeder, pero givePermissionTo()
        // lanza PermissionDoesNotExist si alguno faltara.
        $aCrear = array_values(array_unique([...self::PERMISOS_NUEVOS, ...array_keys(self::DERIVACIONES)]));
        $existentes = Permission::where('guard_name', 'api')->pluck('name')->all();
        $creados = array_values(array_diff($aCrear, $existentes));

        if (! $dryRun) {
            foreach ($aCrear as $nombre) {
                Permission::firstOrCreate(['guard_name' => 'api', 'name' => $nombre]);
            }
        }

        $otorgados = [];

        foreach (Role::with('permissions')->where('guard_name', 'api')->get() as $role) {
            if ($role->name === 'Super-Admin') {
                continue; // bypasea todo vía Gate::before()
            }
            $faltantes = $this->derivadosFaltantes($role->permissions->pluck('name')->all());
            foreach ($faltantes as $permiso) {
                $otorgados[] = ['tipo' => 'rol', 'nombre' => $role->name, 'permiso' => $permiso];
            }
            if (! $dryRun && $faltantes) {
                $role->givePermissionTo($faltantes);
            }
        }

        foreach (User::with('permissions')->whereHas('permissions')->get() as $user) {
            $faltantes = $this->derivadosFaltantes($user->permissions->pluck('name')->all());
            // Si ya lo recibe por un rol, no hace falta duplicarlo como directo.
            $porRol = $user->getPermissionsViaRoles()->pluck('name')->all();
            $faltantes = array_values(array_diff($faltantes, $porRol));
            foreach ($faltantes as $permiso) {
                $otorgados[] = ['tipo' => 'usuario', 'nombre' => $user->email, 'permiso' => $permiso];
            }
            if (! $dryRun && $faltantes) {
                $user->givePermissionTo($faltantes);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return ['creados' => $creados, 'otorgados' => $otorgados];
    }

    /** @param string[] $tiene */
    private function derivadosFaltantes(array $tiene): array
    {
        $faltantes = [];
        foreach (self::DERIVACIONES as $destino => $fuentes) {
            if (! in_array($destino, $tiene, true) && array_intersect($fuentes, $tiene)) {
                $faltantes[] = $destino;
            }
        }

        return $faltantes;
    }
}
