<?php

// Módulo Créditos (00 1.6) — mora habilitable: el negocio decide si un crédito cobra interés
// moratorio. Default en la configuración, copiado al crédito y congelado al activarlo, igual que
// días de gracia y tope. false = el motor no genera mora (el atraso se sigue contando).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credito_configuracion', function (Blueprint $table) {
            $table->boolean('cobra_mora')->default(true)->comment('Default de los créditos nuevos: si cobran interés moratorio (1.6).');
        });
        Schema::table('creditos', function (Blueprint $table) {
            $table->boolean('cobra_mora')->default(true)->comment('Si el crédito cobra interés moratorio; congelado al activar (1.6).');
        });
    }

    public function down(): void
    {
        Schema::table('creditos', fn (Blueprint $table) => $table->dropColumn('cobra_mora'));
        Schema::table('credito_configuracion', fn (Blueprint $table) => $table->dropColumn('cobra_mora'));
    }
};
