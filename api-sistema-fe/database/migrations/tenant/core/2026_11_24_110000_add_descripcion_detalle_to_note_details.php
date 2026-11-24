<?php

// T-126 — `note_details.descripcion_detalle`: copia de sale_details.descripcion_detalle (Agencia:
// servicios con su fecha o el «Texto para este comprobante»). Sin ella, la NC de una venta de
// reserva decía solo el título y no coincidía con el comprobante que corrige. Las notas
// anteriores quedan en NULL a propósito: ya están emitidas y no deben cambiar.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('note_details', function (Blueprint $table) {
            $table->text('descripcion_detalle')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('note_details', function (Blueprint $table) {
            $table->dropColumn('descripcion_detalle');
        });
    }
};
