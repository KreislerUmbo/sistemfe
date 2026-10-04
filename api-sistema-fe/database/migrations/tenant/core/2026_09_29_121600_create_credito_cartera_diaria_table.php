<?php

// Módulo Créditos (plan §2 `credito_cartera_diaria`, 1.16) — foto nocturna de la
// cartera para históricos rápidos. Solo lectura; "hoy" se calcula en vivo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_cartera_diaria', function (Blueprint $table) {
            $table->comment('Foto diaria de la cartera por crédito (job nocturno).');
            $table->id();
            $table->date('fecha_corte')->comment('Día de la foto.');
            $table->foreignId('credito_id')->comment('Crédito (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->unsignedBigInteger('cliente_id')->comment('clients.id a la fecha de corte.');
            $table->unsignedBigInteger('cobrador_id')->nullable()->comment('users.id del cobrador a la fecha de corte.');
            $table->decimal('saldo_capital', 12, 2)->comment('Capital pendiente.');
            $table->decimal('saldo_interes', 12, 2)->comment('Interés pendiente.');
            $table->decimal('mora_pendiente', 12, 2)->comment('Mora pendiente a la fecha de corte.');
            $table->integer('dias_atraso')->comment('Días calendario de atraso (12.12).');
            $table->string('rango_atraso', 10)->comment('Rango de morosidad: al_dia, 1-7, 8-15, 16-30, 31-60, 60+.');
            $table->string('estado', 20)->comment('creditos.estado a la fecha de corte.');
            $table->timestamps();

            $table->unique(['fecha_corte', 'credito_id']);
            $table->index(['fecha_corte', 'cobrador_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_cartera_diaria');
    }
};
