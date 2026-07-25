<?php

// Módulo Caja — Fase 2 (plan-modulo-caja.md §3, columna ya anticipada ahí
// mismo pero omitida por error en el prompt de Fase 1). Archivo propio, no
// se edita la migración original de Fase 0 (create_payment_methods_table.php,
// ya corrida en sandbox) — mismo criterio que alter_companies_add_cash_settings.php.
//
// Sin dato aquí: el backfill (EFECTIVO=true, resto=false) vive en
// PaymentMethodSeeder (idempotente, se resincroniza si el seeder se vuelve a
// correr), no en un UPDATE suelto de una sola vez en esta migración — así
// reconstruir la base desde cero también queda correcto.
//
// Default 'false' a nivel de columna: un método nuevo que un tenant agregue
// desde su propio CRUD (Fase 0, PaymentMethodController) nace sin afectar el
// arqueo de efectivo hasta que alguien lo marque explícitamente — más seguro
// que asumir 'true' por default.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('affects_cash_count')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('affects_cash_count');
        });
    }
};
