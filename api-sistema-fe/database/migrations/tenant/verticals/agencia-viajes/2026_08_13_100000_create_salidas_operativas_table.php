<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Salida operativa" — agrupa reserva_items de DISTINTAS reservas que
// comparten el mismo tour_origen_id + fecha y son modalidad='compartido'.
// El guía se asigna acá UNA vez, no por reserva. El proveedor de
// transporte NO se centraliza (confirmado que varía por reserva incluso
// dentro de la misma salida) — sigue viviendo en reserva_items.
// cupo_maximo/vehiculo_descripcion son informativos, sin ninguna
// validación/alerta encima a propósito (el cupo real no es un dato fijo
// del catálogo, varía según qué unidad sale ese día).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salidas_operativas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_origen_id')->nullable()->constrained('paquetes_plantilla')->nullOnDelete();
            $table->date('fecha');
            $table->time('hora')->nullable();
            $table->foreignId('guia_id')->nullable()->constrained('guias')->nullOnDelete();
            $table->integer('cupo_maximo')->nullable();
            $table->string('vehiculo_descripcion')->nullable();
            $table->enum('estado', ['activa', 'cancelada'])->default('activa');
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Índice único parcial: evita duplicados por condición de carrera
        // si dos reservas del mismo tour_origen_id/fecha se aceptan casi
        // al mismo tiempo. Solo aplica cuando tour_origen_id no es null
        // (las salidas armadas a mano, sin tour_origen_id, no tienen
        // restricción — pueden convivir varias sin fecha/tour en común).
        DB::statement('CREATE UNIQUE INDEX salidas_operativas_tour_fecha_unique ON salidas_operativas (tour_origen_id, fecha) WHERE tour_origen_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('salidas_operativas');
    }
};
