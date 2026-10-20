<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_19_100000_add_residencia_to_reserva_pasajeros_table.php
//
// Fila 13, fase 1c (C1). La reserva necesita la residencia de cada pasajero
// para saber cuánto le toca de la línea de tour al facturar por grupo de
// pasajeros (TourLineaService::precioDeLista()). Se copia de
// cotizacion_pasajeros.residencia al aceptar la alternativa. Las reservas
// anteriores quedan como 'nacional' (decisión del usuario, 18-oct-2026).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->string('residencia')->default('nacional'); // 'nacional' | 'extranjero'
        });
    }

    public function down(): void
    {
        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->dropColumn('residencia');
        });
    }
};
