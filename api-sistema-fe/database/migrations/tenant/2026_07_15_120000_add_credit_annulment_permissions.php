<?php

// Permisos del módulo Amortizaciones (§7 del plan, decisión cerrada desde
// Fase 1) — mismo criterio que add_advance_permissions.php: solo se crean
// las filas (firstOrCreate, no choca con datos existentes), sin asignarlas
// a ningún rol operativo. Super-Admin los tiene todos vía Gate::before sin
// depender de estas filas (AppServiceProvider.php, hasRole('Super-Admin')).
//
// Verificado (no asumido) que hoy no existe un rol "Supervisor"/
// "Administrador" en el proyecto — los 7 roles reales son Super-Admin,
// Contador, Jefe de Ventas, Jefe de Almacen, Cajero, Vendedor, Cliente.
// Mismo criterio que los 3 permisos de Adelantos (register_advance/
// list_advance/refund_advance), que tampoco se asignaron a ningún rol
// operativo — queda para decidirse después desde la UI de Roles y
// Permisos, no se le fuerza el permiso a "Jefe de Ventas" ni a ningún
// otro rol existente por no ser su responsabilidad hoy (ventas, no
// cobranzas/anulaciones).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['anular-cuota-credito', 'anular-pago-credito'] as $name) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $name]);
        }
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->whereIn('name', ['anular-cuota-credito', 'anular-pago-credito'])
            ->delete();
    }
};
