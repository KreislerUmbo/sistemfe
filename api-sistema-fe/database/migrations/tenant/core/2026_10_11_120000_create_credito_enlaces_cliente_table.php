<?php

// Módulo Créditos — Fase 5b (05b-mi-credito-cliente.md). Link permanente por cliente para que
// consulte su crédito ("Mi crédito") sin usuario ni contraseña. El token se guarda cifrado (hay
// que reimprimirlo en cada recibo) y además como hash para buscarlo; regenerar cambia ambos y sube
// la versión, lo que invalida los celulares ya recordados.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_enlaces_cliente', function (Blueprint $table) {
            $table->comment('Link "Mi crédito" de cada cliente (05b): uno vigente por cliente.');
            $table->id();
            $table->foreignId('cliente_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->text('token')->comment('Token del link, cifrado con la APP_KEY (se reimprime en recibos).');
            $table->char('token_hash', 64)->unique()->comment('SHA-256 del token, para buscarlo sin descifrar.');
            $table->unsignedInteger('version')->default(1)->comment('Sube al regenerar: invalida los celulares recordados.');
            $table->unsignedSmallInteger('intentos_fallidos')->default(0)->comment('Fallos seguidos con los 4 dígitos.');
            $table->timestamp('bloqueado_hasta')->nullable()->comment('5 fallos → bloqueado 15 minutos.');
            $table->unsignedInteger('accesos')->default(0);
            $table->timestamp('ultimo_acceso_en')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable()->comment('users.id que lo generó o regeneró (sin FK).');
            $table->timestamp('regenerado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_enlaces_cliente');
    }
};
