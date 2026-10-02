<?php

// Fase 4c (regla decidida 01-oct-2026, todos los giros): el número de documento de un cliente
// no se repite (por tipo + número). Hasta ahora solo lo cuidaba ClientController en PHP, sin
// índice: dos altas simultáneas podían duplicarlo. Excluye 'SND' (sin documento, comparten
// '00000000') y los eliminados (soft delete).
// Si ya hay duplicados, se detiene listándolos en vez de fallar a medias: hay que resolverlos
// (fusionar o corregir) y volver a correr la migración.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FILTRO = "deleted_at IS NULL AND type_document <> 'SND'";

    public function up(): void
    {
        $duplicados = DB::select(
            'SELECT type_document, n_document, string_agg(id::text, \', \' ORDER BY id) AS ids FROM clients WHERE '
            . self::FILTRO . ' GROUP BY type_document, n_document HAVING count(*) > 1'
        );
        if ($duplicados !== []) {
            $lista = implode('; ', array_map(
                static fn ($d): string => "{$d->type_document} {$d->n_document} (ids {$d->ids})",
                $duplicados,
            ));
            throw new RuntimeException("Hay clientes con el mismo documento; resolverlos antes de migrar: {$lista}");
        }

        DB::statement('CREATE UNIQUE INDEX clients_documento_unico ON clients (type_document, n_document) WHERE ' . self::FILTRO);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS clients_documento_unico');
    }
};
