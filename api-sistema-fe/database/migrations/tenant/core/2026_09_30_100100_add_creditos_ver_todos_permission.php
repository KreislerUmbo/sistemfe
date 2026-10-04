<?php

// Módulo Créditos — Fase 3 (03-api.md, decisión 4). Sin creditos.ver_todos, un
// usuario solo ve los créditos de los clientes asignados a él en
// cartera_asignaciones (vigentes). Lo reciben Administrador y Cajero
// (CreditosRolesSeeder); en tenants ya provisionados con giro 'creditos' se
// asigna aquí a esos dos roles si existen.

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISO = 'creditos.ver_todos';
    private const ROLES = ['Administrador de créditos', 'Cajero de créditos'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permiso = Permission::firstOrCreate(['guard_name' => 'api', 'name' => self::PERMISO]);

        Role::where('guard_name', 'api')->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $rol) => $rol->hasPermissionTo($permiso) || $rol->givePermissionTo($permiso));
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')->where('name', self::PERMISO)->delete();
    }
};
