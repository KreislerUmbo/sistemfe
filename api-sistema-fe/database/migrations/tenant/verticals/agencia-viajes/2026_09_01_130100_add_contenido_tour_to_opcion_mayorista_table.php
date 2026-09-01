<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_130100_add_contenido_tour_to_opcion_mayorista_table.php
//
// Sesión 12e — vínculo opcional a contenido_tour + SNAPSHOT de
// descripción/fotos al vincular (no referencia viva — cierra §23.1.8 de
// la auditoría: editar contenido_tour después no debe reescribir en
// silencio el PDF de una cotización ya entregada al cliente, mismo
// principio de congelamiento que el resto del sistema, ver §2).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcion_mayorista', function (Blueprint $table) {
            $table->foreignId('contenido_tour_id')->nullable()->constrained('contenido_tour')->nullOnDelete();
            $table->text('contenido_tour_descripcion_snapshot')->nullable();
            $table->json('contenido_tour_fotos_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opcion_mayorista', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contenido_tour_id');
            $table->dropColumn(['contenido_tour_descripcion_snapshot', 'contenido_tour_fotos_snapshot']);
        });
    }
};
