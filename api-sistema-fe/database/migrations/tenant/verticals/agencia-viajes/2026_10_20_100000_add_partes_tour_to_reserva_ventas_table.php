<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_20_100000_add_partes_tour_to_reserva_ventas_table.php
//
// Fila 13, fase 1c (C1). Una línea de tour se puede facturar por partes, una
// por grupo de pasajeros. Cada factura (reserva_venta) guarda cuánto cobró de
// cada línea de tour:
//   [{"reserva_item_id": 12, "monto": 270.0, "cierra": false}, ...]
// "cierra" = esta factura cobró a los últimos pasajeros que faltaban de la
// línea (se llevó también los centavos del redondeo). Que un pasajero no se
// facture dos veces ya lo asegura reserva_pasajero_ids; acá solo van montos.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_ventas', function (Blueprint $table) {
            $table->json('partes_tour')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reserva_ventas', function (Blueprint $table) {
            $table->dropColumn('partes_tour');
        });
    }
};
