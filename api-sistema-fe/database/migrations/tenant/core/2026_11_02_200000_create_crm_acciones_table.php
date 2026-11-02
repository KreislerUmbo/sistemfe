<?php

// T-051 (CRM C2, obs. 13 y 16): próximas acciones con el cliente — llamar, mandar un WhatsApp,
// cobrar un saldo… con fecha y responsable. Mismo criterio que crm_actividades (T-050): vive en
// core, por cliente, y la referencia a la cotización o reserva va sin FK porque esas tablas son
// del vertical Agencia.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_acciones', function (Blueprint $table) {
            $table->comment('Próximas acciones con el cliente: qué hacer, cuándo y quién (CRM).');
            $table->id();
            $table->foreignId('cliente_id')->constrained('clients');
            $table->string('referencia_tipo', 20)->nullable()->comment("'cotizacion' | 'reserva' | null");
            $table->unsignedBigInteger('referencia_id')->nullable()->comment('Id de la cotización o reserva (sin FK: tablas del vertical)');
            $table->string('tipo', 20)->comment("'llamar' | 'whatsapp' | 'enviar_cotizacion' | 'cobrar_saldo' | 'saludar' | 'otro'");
            $table->string('descripcion', 500)->nullable();
            $table->date('fecha')->comment('Día de negocio (hora Perú) en que toca hacerla');
            $table->foreignId('responsable_id')->constrained('users');
            $table->timestamp('hecha_en')->nullable()->comment('UTC; null = pendiente');
            $table->foreignId('hecha_por')->nullable()->constrained('users');
            $table->foreignId('creada_por')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['responsable_id', 'hecha_en', 'fecha']);
            $table->index(['referencia_tipo', 'referencia_id']);
            $table->index('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_acciones');
    }
};
