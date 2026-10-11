<?php

// Fase 5, entrega 2 (05-avisos-cobranza.md): dispositivos donde cada usuario activó los avisos
// push (Web Push). Una fila por navegador/celular; el servicio de push del navegador la invalida
// (404/410) cuando el usuario desinstala o borra datos, y ahí se borra sola.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_suscripciones', function (Blueprint $table) {
            $table->comment('Suscripciones Web Push de cada usuario (un registro por dispositivo).');
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('endpoint')->comment('URL del servicio de push del navegador.');
            $table->char('endpoint_hash', 64)->unique()->comment('SHA-256 del endpoint (índice único; el endpoint puede ser largo).');
            $table->string('clave_p256dh')->comment('Clave pública del navegador para cifrar el mensaje.');
            $table->string('clave_auth')->comment('Secreto de autenticación del navegador.');
            $table->string('codificacion', 20)->default('aes128gcm');
            $table->string('agente', 255)->nullable()->comment('User-Agent al suscribirse, para reconocer el dispositivo.');
            $table->timestamp('ultimo_envio_en')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_suscripciones');
    }
};
