<?php

// T-162 — las líneas de una compra reflejan las del comprobante del proveedor: descripción, unidad,
// código del ítem y afectación del IGV. `product_id` pasa a opcional (servicios, fletes).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_lineas', function (Blueprint $table) {
            $table->string('descripcion', 500)->nullable();
            $table->string('unidad_medida', 25)->nullable();
            $table->string('codigo_proveedor', 50)->nullable();
            $table->string('tipo_afectacion_igv', 2)->nullable(); // tabla 7 de SUNAT
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->decimal('costo_unitario_base', 18, 6)->nullable()->change(); // solo con producto
        });

        $this->completarLineasExistentes();

        Schema::table('compra_lineas', function (Blueprint $table) {
            $table->string('descripcion', 500)->nullable(false)->change();
            $table->string('unidad_medida', 25)->nullable(false)->change();
        });
    }

    // Hasta aquí toda línea tenía producto: descripción y unidad salen de él. La afectación se
    // deduce del IGV de la línea o de la cabecera; si no se puede, queda vacía y se informa.
    private function completarLineasExistentes(): void
    {
        $filas = DB::table('compra_lineas as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('compras as c', 'c.id', '=', 'l.compra_id')
            ->orderBy('l.id')
            ->get([
                'l.id', 'l.compra_id', 'l.igv', 'p.id as producto_id', 'p.title', 'p.unidad_medida',
                'c.base_gravada', 'c.exonerado', 'c.inafecto',
            ]);

        $sinAfectacion = [];
        foreach ($filas as $fila) {
            $afectacion = match (true) {
                (float) $fila->igv > 0 => '10',
                (float) $fila->base_gravada == 0 && (float) $fila->inafecto == 0 && (float) $fila->exonerado > 0 => '20',
                (float) $fila->base_gravada == 0 && (float) $fila->exonerado == 0 && (float) $fila->inafecto > 0 => '30',
                default => null,
            };
            if ($afectacion === null) {
                $sinAfectacion[] = "línea {$fila->id} (compra {$fila->compra_id})";
            }

            DB::table('compra_lineas')->where('id', $fila->id)->update([
                'descripcion' => mb_substr(trim((string) $fila->title) ?: "Producto {$fila->producto_id}", 0, 500),
                'unidad_medida' => trim((string) $fila->unidad_medida) ?: 'NIU',
                'tipo_afectacion_igv' => $afectacion,
            ]);
        }

        if ($sinAfectacion) {
            echo PHP_EOL.'T-162: sin afectación del IGV (no se pudo deducir): '.implode(', ', $sinAfectacion).PHP_EOL;
        }
    }

    public function down(): void
    {
        // `product_id` y el costo quedan opcionales: puede haber líneas sin producto.
        Schema::table('compra_lineas', function (Blueprint $table) {
            $table->dropColumn(['descripcion', 'unidad_medida', 'codigo_proveedor', 'tipo_afectacion_igv']);
        });
    }
};
