<?php

// Módulo Créditos (plan §2 `credito_condonaciones`, 1.6) — la condonación vive
// fuera de las aplicaciones para sobrevivir a los recálculos por anulación.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_condonaciones', function (Blueprint $table) {
            $table->comment('Condonaciones de mora por cuota.');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->foreignId('cuota_id')->comment('Cuota (credito_cuotas.id).')->constrained('credito_cuotas')->restrictOnDelete();
            $table->enum('concepto', ['mora'])->default('mora')->comment('Concepto condonado; v1 solo mora.');
            $table->decimal('monto', 12, 2)->comment('Monto condonado.');
            $table->text('motivo')->comment('Motivo obligatorio.');
            $table->enum('estado', ['vigente', 'anulada'])->default('vigente')->comment('Solo las vigentes suman a mora_condonada.');
            $table->unsignedBigInteger('registrado_por')->comment('users.id que condonó (sin FK).');
            $table->unsignedBigInteger('anulado_por')->nullable()->comment('users.id que anuló la condonación (sin FK).');
            $table->timestamp('anulado_en')->nullable()->comment('Momento de la anulación.');
            $table->timestamps();

            $table->index(['cuota_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_condonaciones');
    }
};
