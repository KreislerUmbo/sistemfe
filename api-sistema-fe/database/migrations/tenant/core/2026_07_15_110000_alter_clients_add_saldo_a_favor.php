<?php

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §2.6.
// Solo 'saldo_a_favor' — 'mora_override' queda diferido a v2 (§7), no se
// agrega acá.
//
// Verificado contra information_schema de 'umbo' (dev, no asumido): no
// existe ninguna columna ni tabla relacionada con saldo a favor de cliente
// hoy (tampoco 'client_credit_movements' — quedó diseñada pero nunca
// construida, ver memoria del proyecto). Columna nueva sin relación con
// datos previos: no requiere backfill.
//
// Sin índice: la aplicación de saldo a favor es manual (§7, decisión
// cerrada v1), no forma parte del hot-path del FIFO — no hay una consulta
// frecuente que lo necesite todavía.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('saldo_a_favor', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('saldo_a_favor');
        });
    }
};
