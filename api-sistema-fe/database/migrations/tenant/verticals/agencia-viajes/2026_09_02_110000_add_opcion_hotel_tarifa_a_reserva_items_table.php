<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_02_110000_add_opcion_hotel_tarifa_a_reserva_items_table.php
//
// Sesión M2 — trazabilidad de la cotización a la reserva sin perder el
// hotel (plan-matriz-hoteles-cotizador.md Ronda 5/P13,
// plan-ejecucion-matriz-hoteles-cotizador.md fila M2). Mismo patrón que
// la columna equivalente de alternativa_items (04-ago). Sin esto, un
// ítem origen_tipo=mayorista sin ProveedorTarifa real (matriz
// hotel×habitación de OpcionMayorista) llegaba a la reserva sin ningún
// dato de qué habitación se eligió — reserva_items solo copiaba
// proveedor_tarifa_id, nunca opcion_hotel_tarifa_id.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->foreignId('opcion_hotel_tarifa_id')->nullable()->after('proveedor_tarifa_id')
                ->constrained('opciones_hotel_tarifas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opcion_hotel_tarifa_id');
        });
    }
};
