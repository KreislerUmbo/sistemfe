<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_110100_backfill_alternativa_destino_id_en_items.php
//
// Sesión 12c — asigna a cada AlternativaItem existente el (único) destino
// de su Alternativa, creado por el backfill de 12b. No vuelve a resolver
// texto→catálogo (eso ya lo hizo 12b) — solo reasigna la FK ya resuelta.
// Mismo estilo PHP-loop que 12b, no UPDATE...JOIN específico de Postgres.

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
            DB::table('alternativa_items')
                ->where('alternativa_id', $alternativaId)
                ->update(['alternativa_destino_id' => $destinoId]);
        }
    }

    public function down(): void
    {
        DB::table('alternativa_items')->update(['alternativa_destino_id' => null]);
    }
};
