<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_30_110300_add_tour_origen_id_to_reserva_items_table.php
//
// Sesión 11b4 — mismo campo y mismo propósito que
// alternativa_items.tour_origen_id (2026_07_30_110200), copiado al crear la
// reserva (ReservaController::crearReservaDesdeAlternativa()) para que la
// agrupación visual "Día 1 / Día 2" sobreviva también en la pantalla de
// reserva/checklist operativo, no solo en la cotización.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->foreignId('tour_origen_id')->nullable()->after('proveedor_tarifa_id')->constrained('paquetes_plantilla');
        });
    }

    public function down(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tour_origen_id');
        });
    }
};
