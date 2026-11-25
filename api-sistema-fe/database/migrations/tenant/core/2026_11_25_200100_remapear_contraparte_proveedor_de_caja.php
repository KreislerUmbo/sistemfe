<?php

// T-133 (plan-tours-venta-simple-costo-real.md §3.7c) — `suppliers` se retira: sus filas pasan a
// `proveedores` y cash_movements.counterparty_id (counterparty_type='proveedor') se remapea.
//
// - Se une por documento (sin espacios alrededor); si varios proveedores lo comparten, gana el
//   activo más antiguo.
// - Sin coincidencia (o sin documento) se crea el proveedor, sin tipo: es un proveedor general.
// - Los movimientos ya guardan copia del nombre y documento: no se tocan, ni tampoco los montos.
// - Un counterparty_id que no existía en `suppliers` queda en NULL: conservarlo apuntaría a un
//   proveedor ajeno.
//
// No se revierte: no hay forma de saber cuál era el id anterior.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            return;
        }

        $nuevoId = [];
        foreach (DB::table('suppliers')->orderBy('id')->get() as $supplier) {
            $nuevoId[$supplier->id] = $this->coincidente($supplier) ?? $this->crear($supplier);
        }

        // Se leen todos los movimientos ANTES de escribir: los ids viejos y nuevos se pisan
        // entre sí, y actualizar de a un supplier remapearía dos veces la misma fila.
        $porDestino = DB::table('cash_movements')
            ->where('counterparty_type', 'proveedor')
            ->whereNotNull('counterparty_id')
            ->get(['id', 'counterparty_id'])
            ->groupBy(fn ($m) => $nuevoId[$m->counterparty_id] ?? 0);

        foreach ($porDestino as $destino => $movimientos) {
            DB::table('cash_movements')
                ->whereIn('id', $movimientos->pluck('id'))
                ->update(['counterparty_id' => $destino ?: null]);
        }
    }

    public function down(): void
    {
        //
    }

    private function coincidente(object $supplier): ?int
    {
        $documento = trim((string) $supplier->document);
        if ($documento === '') {
            return null;
        }

        return DB::table('proveedores')
            ->whereRaw('trim(numero_documento) = ?', [$documento])
            ->orderByDesc('estado')
            ->orderBy('id')
            ->value('id');
    }

    private function crear(object $supplier): int
    {
        // `suppliers` admitía textos más largos que `proveedores`: lo que no entra queda en
        // observaciones.
        $recortados = [];
        $recortar = function (?string $valor, int $largo) use (&$recortados): ?string {
            $valor = trim((string) $valor);
            if (mb_strlen($valor) > $largo) {
                $recortados[] = $valor;
            }

            return $valor === '' ? null : mb_substr($valor, 0, $largo);
        };

        $datos = [
            'razon_social' => $recortar($supplier->name, 250) ?? 'Sin nombre',
            'numero_documento' => $recortar($supplier->document, 30),
            'telefono' => $recortar($supplier->phone, 50),
            'estado' => (bool) $supplier->is_active,
            'created_at' => $supplier->created_at,
            'updated_at' => $supplier->updated_at,
        ];
        $datos['observaciones'] = $recortados ? 'Datos completos de Caja: '.implode(' | ', $recortados) : null;

        return DB::table('proveedores')->insertGetId($datos);
    }
};
