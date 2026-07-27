<?php

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4). Aperturas/turnos de caja.
//
// Restricción de concurrencia (plan §4, "no dejar para después"): un índice
// único parcial garantiza a nivel de Postgres que solo puede existir UNA fila
// con status='open' por cash_register_id — esto es lo que respalda el
// lockForUpdate() que usará Fase 2, no un sustituto de él. Blueprint no tiene
// soporte nativo para índices únicos parciales (WHERE), así que se usa
// DB::statement() — mismo recurso que ya usa fix_sales_relax_columns.php en
// este proyecto para todo lo que Blueprint no cubre.
//
// 'status' es string plano (no enum de Blueprint), mismo criterio que
// cash_concepts.direction en Fase 0 — aquí además porque el índice parcial de
// abajo compara contra el literal 'open' vía SQL crudo, y un CHECK/enum de
// Postgres no aporta nada adicional a esa garantía.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cash_register_id');
            $table->foreign('cash_register_id')->references('id')->on('cash_registers')->onDelete('restrict');

            $table->unsignedBigInteger('opened_by');
            $table->foreign('opened_by')->references('id')->on('users')->onDelete('restrict');

            $table->unsignedBigInteger('closed_by')->nullable();
            $table->foreign('closed_by')->references('id')->on('users')->onDelete('restrict');

            $table->decimal('opening_amount', 10, 2);
            $table->boolean('opening_amount_adjusted')->default(false);

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            $table->string('status')->default('open');

            $table->decimal('expected_cash', 10, 2)->nullable();
            $table->decimal('counted_cash', 10, 2)->nullable();
            $table->decimal('difference', 10, 2)->nullable();
            $table->text('difference_reason')->nullable();
            $table->text('closing_notes')->nullable();
            $table->string('shift_label')->nullable();

            $table->timestamps();

            $table->index('cash_register_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX cash_sessions_one_open_per_register ' .
            "ON cash_sessions (cash_register_id) WHERE status = 'open'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cash_sessions_one_open_per_register');
        Schema::dropIfExists('cash_sessions');
    }
};
