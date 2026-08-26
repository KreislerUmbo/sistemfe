<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_26_160300_add_reservas_generadas_to_cotizaciones_table.php
//
// Módulo 12 — plan-modulo-codigos-numeracion.md §6.4: contador acotado por
// cotización (0 a un puñado de reservas en el peor caso), usado por
// App\Services\AgenciaViajes\CodigoGeneradorService::generarParaReserva()
// para saber si la reserva que se está generando es la primera de esa
// cotización (código limpio) o una adicional (sufijo "-2", "-3"...). Sin
// relación con codigo_secuencias — no compite por ese lock.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->unsignedInteger('reservas_generadas')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('reservas_generadas');
        });
    }
};
