<?php

// Módulo Créditos (plan §2 `credito_documentos`, 1.15) — contrato congelado al
// activar (PDF + versión de plantilla + hash) y contrato firmado subido.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_documentos', function (Blueprint $table) {
            $table->comment('Documentos guardados de un crédito (contrato generado y firmado).');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->enum('tipo', ['contrato', 'contrato_firmado'])->comment('contrato = PDF congelado; contrato_firmado = escaneo subido.');
            $table->string('ruta_archivo', 255)->comment('Ruta en el disco privado del tenant.');
            $table->integer('plantilla_version')->nullable()->comment('Versión de credito_plantillas usada (solo contrato).');
            $table->string('hash', 64)->nullable()->comment('SHA-256 del archivo.');
            $table->unsignedBigInteger('registrado_por')->comment('users.id (sin FK).');
            $table->timestamps();

            $table->index(['credito_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_documentos');
    }
};
