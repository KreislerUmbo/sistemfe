<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_30_100100_add_agencia_reservas_permission.php
//
// Sesión 11c del vertical Agencia de Viajes — permiso nuevo para
// reserva/pasajeros/venta-directa. Propio, no reusa agencia.cotizaciones:
// aunque ambos son operación diaria de un vendedor, reserva es un paso
// posterior y distinto (control operativo de pasajeros/ítems, no armado de
// precio) — mismo criterio de separación ya usado entre
// agencia.proveedores/agencia.cotizaciones (Sesión 11b).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'agencia.reservas']);
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')->where('name', 'agencia.reservas')->delete();
    }
};
