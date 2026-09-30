<?php

// Módulo Créditos (plan §2 `credito_cliente_limites`, 1.17) — ajustes de límites
// por cliente sin tocar la tabla clients.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_cliente_limites', function (Blueprint $table) {
            $table->comment('Límites de otorgamiento ajustados por cliente.');
            $table->id();
            $table->foreignId('cliente_id')->unique()->comment('Cliente (clients.id).')->constrained('clients')->restrictOnDelete();
            $table->smallInteger('max_creditos_activos')->nullable()->comment('null = usar la configuración.');
            $table->decimal('deuda_maxima', 12, 2)->nullable()->comment('null = usar la configuración.');
            $table->boolean('bloqueado')->default(false)->comment('Bloqueo manual para nuevos créditos.');
            $table->text('motivo_bloqueo')->nullable()->comment('Motivo del bloqueo.');
            $table->unsignedBigInteger('actualizado_por')->nullable()->comment('users.id (sin FK).');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_cliente_limites');
    }
};
