<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\ProveedorTarifa;
use Illuminate\Support\Facades\DB;

/**
 * T-028 (H17) — antes del fix, editar el precio de una tarifa usada dejaba los
 * tours (paquete_plantilla_items) apuntando a la versión vieja. Reapunta cada
 * ítem cuya tarifa ya no está vigente a la tarifa vigente equivalente (mismo
 * servicio, tipo, modalidad, habitación, temporada y diferenciador), solo si
 * hay exactamente una. Cada cambio queda en ajustes_plantilla_tarifa. Correrlo
 * de nuevo no cambia nada.
 */
class PlantillasATarifaVigenteService
{
    /**
     * @return array{cambios: list<array{item_id: int, tarifa_anterior_id: int, tarifa_nueva_id: int}>, revisar: list<array{item_id: int, tarifa_anterior_id: int, motivo: string}>}
     */
    public function reapuntar(bool $simular): array
    {
        $cambios = [];
        $revisar = [];

        $items = PaquetePlantillaItem::whereNotNull('proveedor_tarifa_id')
            ->with('proveedorTarifa')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $anterior = $item->proveedorTarifa;
            if (! $anterior || $anterior->estaVigente()) {
                continue;
            }

            $equivalentes = ProveedorTarifa::vigentes()
                ->where('proveedor_servicio_id', $anterior->proveedor_servicio_id)
                ->where('tipo_tarifa', $anterior->tipo_tarifa)
                ->where('modalidad', $anterior->modalidad)
                ->where('tipo_habitacion', $anterior->tipo_habitacion)
                ->where('temporada_id', $anterior->temporada_id)
                ->get()
                // diferenciador es JSON: se compara en PHP, no en SQL.
                ->filter(fn (ProveedorTarifa $t) => ($t->diferenciador ?? []) == ($anterior->diferenciador ?? []))
                ->values();

            if ($equivalentes->count() !== 1) {
                $revisar[] = [
                    'item_id' => $item->id,
                    'tarifa_anterior_id' => $anterior->id,
                    'motivo' => $equivalentes->isEmpty() ? 'sin tarifa vigente equivalente' : 'varias tarifas vigentes equivalentes',
                ];

                continue;
            }

            $nueva = $equivalentes->first();

            if (! $simular) {
                DB::transaction(function () use ($item, $anterior, $nueva) {
                    DB::table('ajustes_plantilla_tarifa')->insert([
                        'paquete_plantilla_item_id' => $item->id,
                        'tarifa_anterior_id' => $anterior->id,
                        'tarifa_nueva_id' => $nueva->id,
                    ]);
                    $item->update(['proveedor_tarifa_id' => $nueva->id]);
                });
            }

            $cambios[] = ['item_id' => $item->id, 'tarifa_anterior_id' => $anterior->id, 'tarifa_nueva_id' => $nueva->id];
        }

        return ['cambios' => $cambios, 'revisar' => $revisar];
    }
}
