<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_130200_add_contenido_tour_to_opcion_mayorista_opcionales_table.php
//
// Sesión 12e — mismo vínculo + snapshot que 2026_09_01_130100_..., ahora
// sobre opcion_mayorista_opcionales (los tours opcionales tipo "Excursión
// San Blas" — el caso que más directamente mapea a contenido_tour).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcion_mayorista_opcionales', function (Blueprint $table) {
            $table->foreignId('contenido_tour_id')->nullable()->constrained('contenido_tour')->nullOnDelete();
            $table->text('contenido_tour_descripcion_snapshot')->nullable();
            $table->json('contenido_tour_fotos_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opcion_mayorista_opcionales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contenido_tour_id');
            $table->dropColumn(['contenido_tour_descripcion_snapshot', 'contenido_tour_fotos_snapshot']);
        });
    }
};
