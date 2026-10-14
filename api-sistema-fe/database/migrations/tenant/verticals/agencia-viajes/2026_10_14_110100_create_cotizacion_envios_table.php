<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Historial de envíos de cotizaciones por correo
// (plan-modulo-envio-correo-cotizacion.md §5.3). Toda solicitud deja una fila,
// también las que fallan: ningún envío silencioso (mismo criterio que Caja).
// También es la fuente del límite diario por tenant.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizacion_envios', function (Blueprint $table) {
            $table->id();
            // Nulos en un correo de prueba de la configuración (tipo = 'prueba').
            $table->foreignId('cotizacion_id')->nullable()->constrained('cotizaciones')->nullOnDelete();
            $table->foreignId('alternativa_id')->nullable()->constrained('alternativas')->nullOnDelete();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('tipo', 20)->default('cotizacion'); // cotizacion | prueba
            $table->json('para');
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('remitente', 255);
            $table->string('modo_remitente', 30); // dominio_agencia | plataforma
            $table->string('asunto', 255);
            $table->text('cuerpo');
            $table->json('adjuntos')->nullable(); // [{nombre, bytes}]
            $table->string('estado', 20); // enviado | fallido
            $table->text('error')->nullable();
            $table->string('message_id', 255)->nullable();
            $table->timestamps();

            $table->index(['alternativa_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_envios');
    }
};
