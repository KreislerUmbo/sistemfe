<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_30_100000_add_ajuste_de_item_id_to_alternativa_items.php
//
// T-005 (Modificar reserva, D-AGE-032): marca un ítem como "Ajuste — <servicio>", el servicio que
// cobra la diferencia cuando sube el precio de un servicio ya facturado. Apunta al ítem de la
// versión nueva que lleva el precio subido. NULL = ítem que no es un ajuste.
//
// La marca existe para que la modificación siguiente no cuente el ajuste dos veces: la copia de
// T-002 no lo copia y la diferencia de T-003 no lo trata como servicio de la reserva (el precio
// que ya cobra está dentro del ítem que ajusta).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->foreignId('ajuste_de_item_id')->nullable()
                ->constrained('alternativa_items')->nullOnDelete()
                ->comment('Si es un servicio de ajuste: el ítem facturado cuyo aumento de precio cobra (solo en modificaciones de reserva)');
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ajuste_de_item_id');
        });
    }
};
