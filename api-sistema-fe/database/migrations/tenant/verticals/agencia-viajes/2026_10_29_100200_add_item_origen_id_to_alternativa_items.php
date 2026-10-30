<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_29_100200_add_item_origen_id_to_alternativa_items.php
//
// T-002 (Modificar reserva, D-AGE-031): cada ítem de la alternativa de una modificación
// recuerda de cuál ítem de la versión vendida viene. Al aplicar (T-005), lo no facturado se
// re-apunta al ítem nuevo y lo facturado sigue en el vendido. NULL = servicio agregado en la
// modificación (o ítem de una alternativa que no es copia de modificación).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->foreignId('item_origen_id')->nullable()
                ->constrained('alternativa_items')->nullOnDelete()
                ->comment('Ítem de la versión vendida del que se copió este (solo en modificaciones de reserva)');
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_origen_id');
        });
    }
};
