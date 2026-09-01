<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_120200_add_unique_index_alternativa_destino_opcion_mayorista.php
//
// Sesión 12d — equivalente del índice único parcial de 12a
// (opcion_mayorista_alternativa_elegida_unique, sobre alternativa_id)
// pero sobre la columna nueva alternativa_destino_id: "solo una
// OpcionMayorista elegida por DESTINO", que es la regla correcta ahora
// que cada destino internacional del viaje puede tener su propio
// comparador de mayoristas (§9 de la auditoría).
//
// Verificación previa corrida contra agencia-demo (único tenant con el
// vertical, 01-sep-2026): 0 alternativa_destino_id con más de una fila
// estado='elegida' (3 filas opcion_mayorista en total, ya backfilleadas).
// Esperado por construcción: el backfill mapea 1:1 desde alternativa_id,
// que 12a ya había verificado sin duplicados — pero se confirmó igual,
// no se asumió.
//
// El índice de 12a (sobre alternativa_id) NO se dropea acá — coexiste
// hasta 12g (limpieza final), mismo criterio que las columnas deprecadas
// de alternativa_items.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX opcion_mayorista_alternativa_destino_elegida_unique ON opcion_mayorista (alternativa_destino_id) WHERE estado = 'elegida'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS opcion_mayorista_alternativa_destino_elegida_unique');
    }
};
