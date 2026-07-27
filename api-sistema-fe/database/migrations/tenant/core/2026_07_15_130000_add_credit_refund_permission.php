<?php

// Permiso del módulo Amortizaciones (§3.12/§4 del plan) — mismo criterio
// que add_advance_permissions.php y 2026_07_15_120000_add_credit_annulment_permissions.php:
// solo se crea la fila (firstOrCreate), sin asignarla a ningún rol
// operativo. Super-Admin lo tiene vía Gate::before sin depender de esta
// fila. Liquidar una devolución con retención mueve dinero real fuera de
// caja — mismo criterio de permiso elevado que anular-cuota-credito/
// anular-pago-credito (Fase 5A).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'liquidar-devolucion-credito']);
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->where('name', 'liquidar-devolucion-credito')
            ->delete();
    }
};
