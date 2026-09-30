<?php

// Módulo Créditos (plan §2 `credito_cargos`, 1.18) — cargos sumados a una cuota.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_cargos', function (Blueprint $table) {
            $table->comment('Cargos adicionales sumados a cuotas.');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->foreignId('cuota_id')->comment('Cuota que lleva el cargo (credito_cuotas.id).')->constrained('credito_cuotas')->restrictOnDelete();
            $table->enum('tipo', ['reprogramacion'])->comment('Origen del cargo.');
            $table->decimal('monto', 12, 2)->comment('Monto del cargo.');
            $table->foreignId('reprogramacion_id')->nullable()->comment('Reprogramación que lo generó.')->constrained('credito_reprogramaciones')->restrictOnDelete();
            $table->enum('estado', ['vigente', 'anulado'])->default('vigente')->comment('Solo los vigentes suman a cargo_monto de la cuota.');
            $table->unsignedBigInteger('registrado_por')->comment('users.id (sin FK).');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_cargos');
    }
};
