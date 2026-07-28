<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_120100_create_reserva_anticipos_table.php
//
// Sesión 8b del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §4.5: etiqueta un adelanto (Advance, core) contra una reserva específica,
// ANTES de que exista el Sale final. El Advance sigue siendo la única
// fuente de verdad del dinero — esta tabla solo lo asocia a una reserva
// puntual, sin duplicar el registro.
//
// reserva_id: FK real a reserva (Sesión 8a).
// advance_id: FK real a App\Models\Advance\Advance (core, tabla 'advances')
// — ya existe, tenant.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_anticipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->constrained('reserva');
            $table->foreignId('advance_id')->constrained('advances');
            $table->decimal('monto_asignado', 10, 2);
            $table->date('fecha_asignacion');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_anticipos');
    }
};
