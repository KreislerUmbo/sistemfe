<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_120100_backfill_alternativa_destino_id_en_opcion_mayorista.php
//
// Sesión 12d — asigna a cada OpcionMayorista existente el (único) destino
// de su Alternativa, creado por el backfill de 12b. Mismo patrón exacto
// que el backfill de ítems de 12c (2026_09_01_110100_..._en_items.php).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $primerDestinoPorAlternativa = [];

        $destinos = DB::table('alternativa_destinos')
            ->orderBy('alternativa_id')
            ->orderBy('orden')
            ->orderBy('id')
            ->get(['id', 'alternativa_id']);

        foreach ($destinos as $destino) {
            if (! isset($primerDestinoPorAlternativa[$destino->alternativa_id])) {
                $primerDestinoPorAlternativa[$destino->alternativa_id] = $destino->id;
            }
        }

        foreach ($primerDestinoPorAlternativa as $alternativaId => $destinoId) {
            DB::table('opcion_mayorista')
                ->where('alternativa_id', $alternativaId)
                ->update(['alternativa_destino_id' => $destinoId]);
        }
    }

    public function down(): void
    {
        DB::table('opcion_mayorista')->update(['alternativa_destino_id' => null]);
    }
};
