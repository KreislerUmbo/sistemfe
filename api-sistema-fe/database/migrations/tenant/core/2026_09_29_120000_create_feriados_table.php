<?php

// Módulo Créditos (plan §2 `feriados`, 1.4) — feriados propios de cada negocio.
// Se siembra con los nacionales al provisionar un tenant de giro 'creditos'
// (FeriadosNacionalesSeeder); cada negocio agrega o quita los suyos. El motor
// los recibe como parámetro (ReglasCalendario), nunca consulta esta tabla.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feriados', function (Blueprint $table) {
            $table->comment('Feriados del negocio; el cronograma los salta si el crédito tiene saltar_feriados (plan 1.4).');
            $table->id();
            $table->date('fecha')->unique()->comment('Día feriado.');
            $table->string('descripcion', 150)->comment('Nombre del feriado (ej. Fiestas Patrias).');
            $table->enum('origen', ['nacional', 'propio'])->default('propio')->comment('nacional = sembrado por el sistema; propio = agregado por el negocio.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feriados');
    }
};
