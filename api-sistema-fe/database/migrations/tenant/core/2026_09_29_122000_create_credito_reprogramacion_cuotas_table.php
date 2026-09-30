<?php

// Módulo Créditos (plan §2 `credito_reprogramacion_cuotas`, 1.18) — historial de
// fechas por cuota (la fecha se modifica en la misma cuota).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_reprogramacion_cuotas', function (Blueprint $table) {
            $table->comment('Fecha anterior y nueva de cada cuota reprogramada.');
            $table->id();
            $table->foreignId('reprogramacion_id')->comment('Reprogramación (credito_reprogramaciones.id).')->constrained('credito_reprogramaciones')->restrictOnDelete();
            $table->foreignId('cuota_id')->comment('Cuota (credito_cuotas.id).')->constrained('credito_cuotas')->restrictOnDelete();
            $table->date('fecha_anterior')->comment('Vencimiento antes de reprogramar.');
            $table->date('fecha_nueva')->comment('Vencimiento nuevo.');
            $table->decimal('mora_congelada', 12, 2)->default(0)->comment('Mora congelada en la cuota al reprogramar.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_reprogramacion_cuotas');
    }
};
