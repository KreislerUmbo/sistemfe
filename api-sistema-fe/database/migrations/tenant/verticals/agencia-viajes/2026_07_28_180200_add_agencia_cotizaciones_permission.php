<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_180200_add_agencia_cotizaciones_permission.php
//
// Sesión 11b del vertical Agencia de Viajes — permiso nuevo, decisión
// explícita (no reusar agencia.proveedores, ver TODO.md para el
// razonamiento completo): cotizar es una operación de VENTA diaria que
// cualquier vendedor necesita hacer, mientras que agencia.proveedores
// gatea el CATÁLOGO/maestro (más admin-level, Sesión 11a). Mezclarlos
// forzaría a dar acceso de gestión de catálogo a cualquier vendedor que
// solo necesita cotizar, o viceversa.
//
// Cubre Cotizacion/Alternativa/AlternativaItem/OpcionMayorista — todo el
// motor del cotizador de esta sesión. El resto de rutas de 11a que este
// controller consulta de solo lectura (proveedor_tarifas, destinos, etc.)
// no exigen agencia.proveedores de nuevo acá — leer para cotizar no es lo
// mismo que administrar el catálogo.

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'agencia.cotizaciones']);
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')->where('name', 'agencia.cotizaciones')->delete();
    }
};
