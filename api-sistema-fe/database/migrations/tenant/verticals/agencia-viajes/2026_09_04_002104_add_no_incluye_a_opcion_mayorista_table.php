<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_04_002104_add_no_incluye_a_opcion_mayorista_table.php
//
// Simulación real del paquete "Panamá 6D/5N" (docs/auxiliares/) — el PDF de una
// alternativa armada con el comparador de mayoristas no tenía dónde imprimir el
// "No incluye" del paquete base (sí existe, y funciona, para cada tour opcional
// vía opcion_mayorista_opcional.no_incluye). Mismo patrón que la migración que
// agregó descripcion_publica el 02-sep-2026.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcion_mayorista', function (Blueprint $table) {
            $table->text('no_incluye')->nullable()->after('incluye');
        });
    }

    public function down(): void
    {
        Schema::table('opcion_mayorista', function (Blueprint $table) {
            $table->dropColumn('no_incluye');
        });
    }
};
