<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_28_090100_add_tratamiento_tributario_to_reserva_items_table.php
//
// Mismo fix que 2026_08_28_090000_..._alternativa_items_table.php, un
// escalón más adelante en la cadena: reserva_item hereda tip_afe_igv/
// destino_tributario del alternativa_item de origen en
// ReservaController::crearReservaItemDesdeAlternativaItem() — sin esto,
// ReservaFacturacionController no tiene de dónde leer el dato real por
// ítem al facturar. Nullable por el mismo motivo (compatibilidad con
// reservas creadas antes de este fix).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->string('tip_afe_igv', 2)->nullable()->after('salida_operativa_id');
            $table->string('destino_tributario')->nullable()->after('tip_afe_igv');
        });
    }

    public function down(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->dropColumn(['tip_afe_igv', 'destino_tributario']);
        });
    }
};
