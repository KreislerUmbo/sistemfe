<?php

// Módulo Créditos — Fase 5 (05-avisos-cobranza.md). Aviso diario "lo que cobras hoy" al
// administrador y a quien cobra. Apagado por defecto: el negocio lo activa desde la
// configuración, así un deploy nunca empieza a mandar correos por sorpresa.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credito_configuracion', function (Blueprint $table) {
            $table->boolean('avisos_activo')->default(false)->comment('Aviso diario de cobranza encendido (05).');
            $table->string('avisos_hora', 5)->default('06:45')->comment('Hora de Lima (HH:MM) desde la que sale el aviso del día.');
            $table->json('avisos_dias')->nullable()->comment('Días ISO (1=lunes … 7=domingo) en que sale el aviso; null = lunes a sábado.');
            $table->boolean('avisos_incluir_atrasados')->default(true)->comment('El aviso suma lo atrasado a lo que vence hoy.');
            $table->boolean('avisos_sin_cobros')->default(true)->comment('Quien ve toda la cartera recibe correo aunque no haya cobros (confirma que el proceso corrió).');
        });

        Schema::create('credito_aviso_preferencias', function (Blueprint $table) {
            $table->comment('Preferencias de cada usuario para los avisos de cobranza (05).');
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('correo')->nullable()->comment('Correo para avisos; solo se usa verificado.');
            $table->timestamp('correo_verificado_en')->nullable()->comment('Se vacía si cambia el correo.');
            $table->boolean('recibir_correo')->default(true);
            $table->boolean('recibir_push')->default(true);
            $table->timestamps();
        });

        Schema::create('credito_avisos', function (Blueprint $table) {
            $table->comment('Registro de cada aviso enviado o intentado (05): idempotencia y seguimiento.');
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 30)->comment('cobranza_dia.');
            $table->date('fecha')->comment('Fecha de negocio (Lima) del aviso.');
            $table->string('canal', 10)->comment('correo | push.');
            $table->string('estado', 10)->comment('pendiente | enviado | fallido | omitido.');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->text('detalle')->nullable()->comment('Error o motivo de omisión.');
            $table->unsignedInteger('cantidad_cobros')->default(0)->comment('Cuotas a cobrar en el aviso.');
            $table->decimal('monto_esperado', 14, 2)->default(0)->comment('Monto a cobrar en el aviso (cuotas + mora).');
            $table->timestamp('enviado_en')->nullable();
            $table->timestamp('abierto_en')->nullable()->comment('Primer clic en el enlace del aviso (push).');
            $table->timestamps();

            $table->unique(['user_id', 'tipo', 'fecha', 'canal']);
            $table->index(['fecha', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_avisos');
        Schema::dropIfExists('credito_aviso_preferencias');
        Schema::table('credito_configuracion', fn (Blueprint $table) => $table->dropColumn([
            'avisos_activo', 'avisos_hora', 'avisos_dias', 'avisos_incluir_atrasados', 'avisos_sin_cobros',
        ]));
    }
};
