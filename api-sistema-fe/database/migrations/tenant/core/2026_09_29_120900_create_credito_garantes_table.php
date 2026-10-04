<?php

// Módulo Créditos (plan §2 `credito_garantes`, 1.10) — garantes registrados
// como clientes para reutilizar datos y ver cuánto respaldan.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_garantes', function (Blueprint $table) {
            $table->comment('Garantes de cada crédito (módulo creditos_garantes).');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->foreignId('cliente_id')->comment('Garante (clients.id).')->constrained('clients')->restrictOnDelete();
            $table->text('observaciones')->nullable()->comment('Notas sobre el garante en este crédito.');
            $table->timestamps();

            $table->unique(['credito_id', 'cliente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_garantes');
    }
};
