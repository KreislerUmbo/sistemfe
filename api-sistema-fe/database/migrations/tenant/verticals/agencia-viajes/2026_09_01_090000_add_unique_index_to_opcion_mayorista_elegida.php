<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_090000_add_unique_index_to_opcion_mayorista_elegida.php
//
// Sesión 12a (Fase 0, auditoría §3.4) — la regla "solo una OpcionMayorista
// elegida por alternativa" dependía enteramente de que
// OpcionMayoristaController::elegir() desmarque la anterior en código, sin
// constraint de base de datos que lo garantice. Mismo patrón ya usado en
// salidas_operativas (2026_08_13_100000_create_salidas_operativas_table.php):
// índice único parcial, solo sobre las filas 'elegida'.
//
// Verificación previa corrida contra agencia-demo (único tenant con este
// vertical, 01-sep-2026): 0 alternativa_id con más de una fila
// estado='elegida' en opcion_mayorista (1 fila 'elegida' en total). Sin
// datos que corregir antes de crear el índice.
//
// Nota para la sesión 12d: cuando OpcionMayorista se mueva a colgar de
// alternativa_destino_id, este índice se reemplaza por uno equivalente
// sobre esa columna — no se anticipa acá.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX opcion_mayorista_alternativa_elegida_unique ON opcion_mayorista (alternativa_id) WHERE estado = 'elegida'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS opcion_mayorista_alternativa_elegida_unique');
    }
};
