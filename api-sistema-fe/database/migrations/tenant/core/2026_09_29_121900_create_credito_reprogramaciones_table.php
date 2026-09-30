<?php

// Módulo Créditos (plan §2 `credito_reprogramaciones`, 1.18) — cabecera de cada
// reprogramación de fechas (sin cambio de montos).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_reprogramaciones', function (Blueprint $table) {
            $table->comment('Reprogramaciones de fechas de un crédito.');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->text('motivo')->comment('Motivo obligatorio.');
            $table->enum('cargo_tipo', ['ninguno', 'fijo', 'interes_por_dias'])->comment('Tipo de cargo aplicado.');
            $table->decimal('cargo_monto', 12, 2)->default(0)->comment('Cargo final (ajustable por el admin).');
            $table->enum('accion_mora', ['mantener', 'condonar', 'no_aplica'])->comment('Qué se hizo con la mora acumulada de cuotas vencidas.');
            $table->unsignedBigInteger('registrado_por')->comment('users.id (sin FK).');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_reprogramaciones');
    }
};
