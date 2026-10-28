<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\AlternativaItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T-024 — antes del fix, duplicar una alternativa copiaba grupo_opcion_id
 * tal cual: la original y la copia compartían el grupo. Busca los grupos
 * presentes en más de una alternativa y deja el id original solo en la
 * primera (por id); cada otra alternativa recibe uno nuevo. Cada fila
 * cambiada queda en ajustes_grupo_opcion. Correrlo de nuevo no cambia nada.
 */
class SepararGruposDuplicadosService
{
    /**
     * @return list<array{grupo: string, alternativa_id: int, grupo_nuevo: ?string, filas: int}>
     *         una entrada por alternativa que recibe (o recibiría) un grupo nuevo
     */
    public function separar(bool $simular): array
    {
        $grupos = AlternativaItem::whereNotNull('grupo_opcion_id')
            ->groupBy('grupo_opcion_id')
            ->havingRaw('COUNT(DISTINCT alternativa_id) > 1')
            ->pluck('grupo_opcion_id');

        $cambios = [];

        foreach ($grupos as $grupo) {
            $alternativas = AlternativaItem::where('grupo_opcion_id', $grupo)
                ->distinct()->orderBy('alternativa_id')->pluck('alternativa_id');

            foreach ($alternativas->slice(1) as $alternativaId) {
                $ids = AlternativaItem::where('alternativa_id', $alternativaId)->where('grupo_opcion_id', $grupo)->pluck('id');
                $nuevo = $simular ? null : (string) Str::uuid();

                if (! $simular) {
                    DB::transaction(function () use ($ids, $grupo, $nuevo) {
                        foreach ($ids as $id) {
                            DB::table('ajustes_grupo_opcion')->insert([
                                'alternativa_item_id' => $id, 'grupo_anterior' => $grupo, 'grupo_nuevo' => $nuevo,
                            ]);
                        }
                        AlternativaItem::whereIn('id', $ids)->update(['grupo_opcion_id' => $nuevo]);
                    });
                }

                $cambios[] = ['grupo' => $grupo, 'alternativa_id' => (int) $alternativaId, 'grupo_nuevo' => $nuevo, 'filas' => $ids->count()];
            }
        }

        return $cambios;
    }
}
