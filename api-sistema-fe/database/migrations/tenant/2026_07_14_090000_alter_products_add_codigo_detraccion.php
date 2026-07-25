<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columna que la migración original de create_products_table (§ ver comentario ahí)
     * dejó fuera a propósito, pendiente de "una migración aparte fechada después" que
     * nunca se escribió. products.codigo_detraccion ya está en Product::$fillable y ya
     * tiene su relación belongsTo(DetractionCode::class, 'codigo_detraccion', 'codigo')
     * y su validación de aplicación en ProductController::store()/update() — sin esta
     * columna, cualquier request que mande codigo_detraccion revienta con "column does
     * not exist" en vez de guardar el dato. Sin FK real a nivel de Postgres (tabla
     * central, distinta base física — mismo criterio que el resto de referencias
     * cross-boundary, ver plan §6).
     */
    public function up(): void
    {
        // Guard: en bases donde ya corrió fix_detraction_codes_rebuild_schema.php
        // (central, 2026_07_13_090500) — hoy solo sv_facturacion, por el
        // Schema::table('products', ...) que trae esa migración — la columna ya
        // existe. Ver diagnóstico de la conversación: no se elimina esta
        // migración porque es la única que provisiona codigo_detraccion en
        // bases de tenant reales (umbo, sandbox, etc.), que nunca corren la
        // migración central.
        if (!Schema::hasColumn('products', 'codigo_detraccion')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('codigo_detraccion', 3)->nullable()->after('is_especial_nota');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'codigo_detraccion')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('codigo_detraccion');
            });
        }
    }
};
