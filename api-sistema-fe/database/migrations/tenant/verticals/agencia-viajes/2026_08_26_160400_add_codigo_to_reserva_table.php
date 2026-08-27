<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_26_160400_add_codigo_to_reserva_table.php
//
// Módulo 12 — plan-modulo-codigos-numeracion.md §4.2/§6.4. Reserva no tiene
// numeración propia: el código se deriva del de su cotización padre
// (App\Services\AgenciaViajes\CodigoGeneradorService::generarParaReserva()),
// cambiando solo el prefijo (C→R) y agregando un sufijo si es la 2da+
// reserva de esa misma cotización.
//
// Nullable: las reservas ya existentes antes de activar este módulo se
// quedan sin código retroactivo — mismo criterio de "sin empalme" que
// tour/paquete/cotización (§4.1: "todos arrancan... el día que se activa la
// configuración", sin backfill de datos previos).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva', function (Blueprint $table) {
            $table->string('codigo')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('reserva', function (Blueprint $table) {
            $table->dropColumn('codigo');
        });
    }
};
