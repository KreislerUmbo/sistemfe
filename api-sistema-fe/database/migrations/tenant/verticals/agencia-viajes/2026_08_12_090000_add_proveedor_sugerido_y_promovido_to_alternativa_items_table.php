<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_12_090000_add_proveedor_sugerido_y_promovido_to_alternativa_items_table.php
//
// Sesión 11q — ítem manual flexible. proveedor_sugerido_manual es dato
// interno (nunca se muestra al cliente), solo prellena "Nombre del
// proveedor" al promover un ítem manual a proveedor real (ver
// AlternativaItemController::promoverAProveedor()). proveedor_promovido_id
// se llena SOLO cuando se ejecuta ese promote — puramente informativo,
// nunca relinkea proveedor_tarifa_id ni cambia origen_tipo: la cotización
// actual no se mueve, el proveedor creado queda disponible para próximas
// cotizaciones.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->string('proveedor_sugerido_manual', 250)->nullable()->after('descripcion_manual');
            $table->foreignId('proveedor_promovido_id')->nullable()
                ->after('proveedor_sugerido_manual')
                ->constrained('proveedores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proveedor_promovido_id');
            $table->dropColumn('proveedor_sugerido_manual');
        });
    }
};
