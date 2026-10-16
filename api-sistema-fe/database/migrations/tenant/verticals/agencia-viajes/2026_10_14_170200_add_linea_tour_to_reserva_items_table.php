<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_14_170200_add_linea_tour_to_reserva_items_table.php
//
// Fila 13, fase 1b (plan-tours-venta-simple-costo-real.md §3.9b, N14). Copia de
// la línea de tour al aceptar (ReservaController::crearReservaItemDesdeAlternativaItem()):
// la carga (para la mezcla de IGV de la 1c y la salida de la fase 2) y la copia
// del costo por servicio (sin ella, el estimado de una salida pasada cambiaría
// cada vez que se actualiza el costo del tour). Solo aditiva.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->uuid('carga_tour')->nullable()->index();
            $table->uuid('carga_combo')->nullable();
            $table->json('desglose_costo_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->dropIndex(['carga_tour']);
            $table->dropColumn(['carga_tour', 'carga_combo', 'desglose_costo_snapshot']);
        });
    }
};
