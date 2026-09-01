<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_120000_add_alternativa_destino_id_to_opcion_mayorista_table.php
//
// Sesión 12d (multi-destino, Fase 2 de auditoria-arquitectonica-agencia-
// viajes.md §19/§20) — OpcionMayorista pasa a colgar de un destino
// concreto del viaje, no de la Alternativa completa: cada destino
// internacional del viaje puede tener su propio comparador de mayoristas
// (§9 de la auditoría). alternativa_id NO se dropea — sigue existiendo
// "en modo lectura de compatibilidad" (código viejo como
// OpcionMayoristaController::index() sigue funcionando sin cambios); se
// dropea recién en 12g, junto con las columnas deprecadas de 12a.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcion_mayorista', function (Blueprint $table) {
            $table->foreignId('alternativa_destino_id')->nullable()->after('alternativa_id')
                ->constrained('alternativa_destinos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opcion_mayorista', function (Blueprint $table) {
            $table->dropConstrainedForeignId('alternativa_destino_id');
        });
    }
};
