<?php

// Módulo Créditos (plan §2 `credito_autorizaciones`, 1.17) — excepciones a los
// límites autorizadas por un admin; aparecen en el reporte de Control.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_autorizaciones', function (Blueprint $table) {
            $table->comment('Autorizaciones de excepción a los límites de otorgamiento.');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito autorizado (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->enum('regla', ['max_creditos', 'deuda_maxima', 'moroso', 'bloqueado'])->comment('Regla exceptuada (ReglaLimite).');
            $table->json('detalle')->comment('Valores al momento de autorizar (Infraccion::detalle).');
            $table->text('motivo')->comment('Motivo obligatorio.');
            $table->unsignedBigInteger('autorizado_por')->comment('users.id con creditos.autorizar_excepcion (sin FK).');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_autorizaciones');
    }
};
