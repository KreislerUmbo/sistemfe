<?php
// Módulo de series de comprobantes — Paso 3.5. Un permiso por cada tipo de
// documento que SaleController::store() puede emitir hoy (01 Factura,
// 03 Boleta, NV Nota de venta) — NO se crean permisos para 07/08 (NC/ND)
// aquí porque esos se emiten desde NotaElectronicaController, un flujo
// separado que este módulo no toca.
//
// Sin esto, register.vue/edit.vue solo pueden filtrar qué tipos ve el
// usuario (UX) — la validación real vive en SaleController::store(), que
// rechaza con 422 si el usuario autenticado no tiene el permiso
// correspondiente al tipo_comprobante_codigo del payload, sin confiar en que
// el frontend filtre correctamente.
//
// Solo se crean las filas (firstOrCreate), sin asignarlas a ningún rol
// operativo por defecto — mismo criterio que el resto de permisos nuevos de
// este proyecto (advances, credit, cash). Al admin/Super-Admin no le cambia
// nada (Gate::before ya le da todo).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'emitir_factura',
        'emitir_boleta',
        'emitir_nota_venta',
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
