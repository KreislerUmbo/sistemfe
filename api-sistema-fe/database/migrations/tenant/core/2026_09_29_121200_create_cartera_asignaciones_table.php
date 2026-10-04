<?php

// Módulo Créditos (plan §2 `cartera_asignaciones`, 1.14) — asignación de
// cartera a cobradores con historial: reasignar cierra la vigencia, no borra.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartera_asignaciones', function (Blueprint $table) {
            $table->comment('Asignación de cartera a cobradores (módulo creditos_cobradores).');
            $table->id();
            $table->unsignedBigInteger('cobrador_id')->comment('users.id del cobrador (sin FK).');
            $table->enum('tipo', ['cliente', 'credito', 'zona'])->comment('Qué se asigna; prioridad crédito > cliente > zona (1.14).');
            $table->unsignedBigInteger('referencia_id')->comment('clients.id, creditos.id o zona según tipo.');
            $table->date('vigente_desde')->comment('Inicio de la asignación.');
            $table->date('vigente_hasta')->nullable()->comment('Fin; null = vigente.');
            $table->unsignedBigInteger('asignado_por')->comment('users.id que asignó (sin FK).');
            $table->timestamps();

            $table->index(['tipo', 'referencia_id', 'vigente_hasta']);
            $table->index(['cobrador_id', 'vigente_hasta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cartera_asignaciones');
    }
};
