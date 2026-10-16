<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_14_170300_add_residencia_to_cotizacion_pasajeros_table.php
//
// Fila 13, fase 1b (plan-tours-venta-simple-costo-real.md §3.3, N2). Residencia
// del pasajero para el precio del tour: un extranjero residente suele pagar
// como nacional, así que NO es la nacionalidad (reserva_pasajeros.nacionalidad
// sigue siendo el dato del pasajero). No cambia el IGV (P2, contador).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_pasajeros', function (Blueprint $table) {
            $table->string('residencia')->default('nacional'); // 'nacional' | 'extranjero'
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_pasajeros', function (Blueprint $table) {
            $table->dropColumn('residencia');
        });
    }
};
