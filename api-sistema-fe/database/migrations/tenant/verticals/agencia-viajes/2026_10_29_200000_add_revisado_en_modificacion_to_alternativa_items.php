<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_29_200000_add_revisado_en_modificacion_to_alternativa_items.php
//
// T-003 (Modificar reserva, R-AGE-06): el vendedor confirma a mano un servicio "para revisar"
// (habitación, transporte privado, mayorista, pasaje aéreo con otra cantidad de pasajeros y el
// mismo precio). Esta marca es esa confirmación; se limpia sola si el ítem o los pasajeros de la
// modificación cambian después (ver AlternativaItem::booted() y actualizarPasajeros()).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->timestamp('revisado_en_modificacion')->nullable()
                ->comment('Cuándo el vendedor confirmó este servicio "para revisar" en una modificación de reserva (UTC)');
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropColumn('revisado_en_modificacion');
        });
    }
};
