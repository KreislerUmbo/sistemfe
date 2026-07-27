<?php

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §2.2.
// Solo se llenan filas si sales.credit_type = 'cuotas_fijas'. La
// periodicidad (mensual/quincenal/...) es solo herramienta de UI para
// pre-generar fechas editables — NO se persiste; lo único que persiste es
// 'fecha_vencimiento' explícita por fila, para soportar cuotas irregulares
// y renegociaciones sin rehacer el cronograma completo.
//
// anulado_por sin FK real a users, siguiendo la convención ya usada en
// notes/advances para columnas de auditoría de usuario (ver comentario en
// create_notes_table.php) — la integridad se resuelve a nivel de
// aplicación.
//
// SIN softDeletes() a propósito (a diferencia del resto del proyecto): un
// ->delete() por error o por bug sobre una cuota debe fallar o quedar
// bloqueado por FK, nunca desaparecer en silencio de los cálculos de saldo
// sin pasar por 'estado = anulada' con motivo_anulacion/anulado_por.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sale_id');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('restrict');

            $table->unsignedSmallInteger('numero_cuota');
            $table->decimal('monto_programado', 12, 2);
            $table->date('fecha_vencimiento');

            $table->enum('estado', ['pendiente', 'pagada', 'parcial', 'vencida', 'anulada'])
                ->default('pendiente');
            $table->text('motivo_anulacion')->nullable();
            $table->unsignedBigInteger('anulado_por')->nullable();
            $table->timestamp('anulado_en')->nullable();

            $table->timestamps();

            $table->index(['sale_id', 'numero_cuota']);
            $table->index(['sale_id', 'fecha_vencimiento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
