<?php

// Permiso del módulo Amortizaciones (§3.13/§4) — mismo criterio que los
// permisos anteriores de Fase 5: solo se crea la fila (firstOrCreate), sin
// asignarla a ningún rol operativo. Super-Admin lo tiene vía Gate::before.
//
// Permiso NUEVO, no se reusa liquidar-devolucion-credito (decisión
// explicada al usuario): §3.12 autoriza que salga dinero de caja (riesgo
// de custodia de efectivo); §3.13 no mueve dinero — es una corrección de
// documento que reasigna trazabilidad de pagos ya existentes
// (replaces_sale_id, origen_application_id). Son categorías de riesgo
// distintas, el plan las trata como dos flujos separados.

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'reemplazar-comprobante-credito']);
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->where('name', 'reemplazar-comprobante-credito')
            ->delete();
    }
};
