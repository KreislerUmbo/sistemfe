<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Configuración del correo con el que la agencia envía sus cotizaciones
// (plan-modulo-envio-correo-cotizacion.md §3.2). Una sola fila, mismo criterio
// que configuracion_agencia. El dominio del remitente NO se guarda acá: sale
// siempre del dominio verificado del tenant (tabla central
// tenant_dominios_correo), el usuario solo elige lo que va antes de la @.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_correo', function (Blueprint $table) {
            $table->id();
            $table->string('remitente_nombre', 120)->nullable();
            $table->string('remitente_local', 64)->nullable();
            $table->string('reply_to', 190)->nullable();
            // Con Brevo el correo no aparece en "Enviados" del buzón de la
            // agencia: la copia oculta le deja constancia.
            $table->boolean('copia_oculta_activa')->default(false);
            $table->string('copia_oculta_email', 190)->nullable();
            // Texto plano con variables {cliente} {codigo} {alternativa}
            // {destino} {fecha_viaje} {vendedor} {vigencia} {agencia}.
            $table->string('asunto_plantilla', 255)->nullable();
            $table->text('cuerpo_plantilla')->nullable();
            $table->text('firma')->nullable();
            $table->boolean('adjuntar_condiciones_por_defecto')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_correo');
    }
};
