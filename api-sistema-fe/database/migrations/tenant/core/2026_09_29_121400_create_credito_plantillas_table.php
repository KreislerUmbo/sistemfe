<?php

// Módulo Créditos (plan §2 `credito_plantillas`, 1.15) — plantilla del contrato
// editable por el negocio. Editar crea una versión nueva, nunca sobrescribe.
// El contenido es HTML sanitizado con variables de lista blanca (nunca Blade).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_plantillas', function (Blueprint $table) {
            $table->comment('Plantillas versionadas de documentos (contrato).');
            $table->id();
            $table->enum('tipo', ['contrato'])->comment('Tipo de documento.');
            $table->integer('version')->comment('Versión; sube en cada edición.');
            $table->text('contenido')->comment('HTML sanitizado con variables {lista_blanca}.');
            $table->boolean('activa')->default(false)->comment('Versión en uso para nuevos contratos.');
            $table->unsignedBigInteger('creado_por')->nullable()->comment('users.id; null si la sembró el sistema (sin FK).');
            $table->timestamps();

            $table->unique(['tipo', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_plantillas');
    }
};
