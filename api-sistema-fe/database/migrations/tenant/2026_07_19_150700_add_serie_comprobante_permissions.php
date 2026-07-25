<?php
// Módulo de series de comprobantes — Paso 3. CRUD de catálogo simple, mismo
// estilo snake_case que register_categorie/list_categorie/edit_categorie
// (PermissionsDemoSeeder) — no el kebab-case de permission:... de
// Amortizaciones/Caja, porque esto es un CRUD de catálogo, no una acción
// puntual de supervisor con enforcement de ruta.
//
// Sin delete_serie_comprobante: no hay borrado real (correlativo_actual>0
// bloquea editar la serie, "desactivar" ya cumple ese rol — mismo criterio
// que payment_methods).
//
// Solo se crean las filas, sin asignarlas a ningún rol operativo por
// defecto — mismo criterio que el resto de permisos nuevos del proyecto.

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'register_serie_comprobante',
        'list_serie_comprobante',
        'edit_serie_comprobante',
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $name]);
        }
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->whereIn('name', self::PERMISSIONS)
            ->delete();
    }
};
