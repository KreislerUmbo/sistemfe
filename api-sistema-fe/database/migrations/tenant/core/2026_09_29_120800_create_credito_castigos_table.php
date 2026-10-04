<?php

// Módulo Créditos (12.12) — intervalos de castigo. Revertir un castigo no genera
// mora retroactiva: el motor recibirá la lista de intervalos (cambio de
// ReglasMora previsto para la Fase 3). creditos.fecha_castigo guarda el vigente.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_castigos', function (Blueprint $table) {
            $table->comment('Historial de castigos (crédito incobrable) y sus reversiones.');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->date('fecha_castigo')->comment('Desde cuándo la mora quedó congelada.');
            $table->date('fecha_reversion')->nullable()->comment('Hasta cuándo; null = castigo vigente.');
            $table->enum('tipo', ['automatico', 'manual'])->comment('automatico = proceso diario por dias_para_castigo; manual = admin (1.19).');
            $table->text('motivo')->nullable()->comment('Motivo del castigo manual.');
            $table->text('motivo_reversion')->nullable()->comment('Motivo de la reversión.');
            $table->unsignedBigInteger('castigado_por')->nullable()->comment('users.id; null si fue automático (sin FK).');
            $table->unsignedBigInteger('revertido_por')->nullable()->comment('users.id que revirtió (sin FK).');
            $table->timestamps();

            $table->index(['credito_id', 'fecha_reversion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_castigos');
    }
};
