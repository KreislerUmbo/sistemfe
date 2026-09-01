<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_110000_add_alternativa_destino_id_to_alternativa_items_table.php
//
// Sesión 12c (multi-destino, Fase 2 de auditoria-arquitectonica-agencia-
// viajes.md §19) — cada AlternativaItem pasa a poder colgar de un destino
// concreto del viaje (alternativa_destinos, 12b), no solo de la
// Alternativa completa. Nullable a propósito: los 9 puntos de creación
// de ítems (AlternativaItemController/ComboExplosionService) no se tocan
// en esta sesión (ver brief PEGAR-EN-CLAUDE-CODE-12c-alternativa-item-destino.md
// §0.4) — quedan sin resolver hasta 12f, que es donde recién se empieza a
// leer este dato.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->foreignId('alternativa_destino_id')->nullable()->after('alternativa_id')
                ->constrained('alternativa_destinos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('alternativa_destino_id');
        });
    }
};
