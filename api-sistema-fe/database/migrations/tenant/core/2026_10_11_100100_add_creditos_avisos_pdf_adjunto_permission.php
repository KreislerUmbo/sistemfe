<?php

// Módulo Créditos — Fase 5 (05-avisos-cobranza.md, decisión 3). El aviso diario lleva el PDF
// de la agenda adjunto solo a quien tiene este permiso; el resto recibe un enlace que exige
// iniciar sesión. No se decide por creditos.ver_todos porque el Cajero también lo tiene.
// En tenants ya provisionados se asigna al "Administrador de créditos" si existe.

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISO = 'creditos.avisos_pdf_adjunto';
    private const ROLES = ['Administrador de créditos'];

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
