<?php
// Mismo patrón que reserva_anticipos (Sesión 8b del vertical Agencia de
// Viajes, ver 2026_07_28_120100_create_reserva_anticipos_table.php):
// etiqueta un adelanto (Advance, core) contra una cotización comercial
// específica, ANTES de que exista el Sale final — para poder cobrar un
// anticipo que arranque el trabajo de la cotización. El Advance sigue
// siendo la única fuente de verdad del dinero (su propio comprobante
// SUNAT, su propio saldo); esta tabla solo lo asocia a una cotización
// puntual, sin duplicar el registro.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_quote_anticipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commercial_quote_id')->constrained('commercial_quotes');
            $table->foreignId('advance_id')->constrained('advances');
            $table->decimal('monto_asignado', 10, 2);
            $table->date('fecha_asignacion');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_quote_anticipos');
    }
};
