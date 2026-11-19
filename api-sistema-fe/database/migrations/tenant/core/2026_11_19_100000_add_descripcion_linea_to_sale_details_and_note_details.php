<?php

// T-108 — Descripción editable de una línea de venta.
//
// `descripcion_linea` es el texto que REEMPLAZA al nombre del producto en el XML,
// el PDF y el ticket (máx. 500 caracteres, varias líneas). NULL = sale el título.
//
// Columna nueva a propósito: `sale_details.description` ya la escribe el flujo de
// adelantos (AdvanceController) y SaleDetailController::store, y `note_details.description`
// es varchar(255) y los PDF de notas ya la usan; reutilizarlas cambiaría documentos
// ya emitidos. `descripcion_detalle` (Agencia) sigue agregándose al título, sin cambios.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->text('descripcion_linea')->nullable();
        });

        Schema::table('note_details', function (Blueprint $table) {
            $table->text('descripcion_linea')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('note_details', function (Blueprint $table) {
            $table->dropColumn('descripcion_linea');
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn('descripcion_linea');
        });
    }
};
