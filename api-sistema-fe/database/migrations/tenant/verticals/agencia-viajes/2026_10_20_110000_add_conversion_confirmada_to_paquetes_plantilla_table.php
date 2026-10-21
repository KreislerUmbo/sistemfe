<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_20_110000_add_conversion_confirmada_to_paquetes_plantilla_table.php
//
// Fila 13, fase 1c — conversión de los tours existentes al modelo nuevo
// (plan-tours-venta-simple-costo-real.md §3.11). El usuario revisa la propuesta
// de cada tour (precio, costo e IGV por servicio) y la confirma uno por uno
// (D20). Estas columnas registran cuándo y quién la confirmó; la pantalla de
// conversión las usa para separar pendientes y confirmados.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paquetes_plantilla', function (Blueprint $table) {
            $table->timestamp('conversion_confirmada_en')->nullable();
            $table->foreignId('conversion_confirmada_por')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paquetes_plantilla', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversion_confirmada_por');
            $table->dropColumn('conversion_confirmada_en');
        });
    }
};
