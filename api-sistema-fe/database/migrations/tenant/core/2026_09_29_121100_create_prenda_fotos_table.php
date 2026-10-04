<?php

// Módulo Créditos (plan §2 `prenda_fotos`, 1.11) — fotos obligatorias al
// ingreso y a la devolución.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prenda_fotos', function (Blueprint $table) {
            $table->comment('Fotos de prendas al ingreso y a la devolución.');
            $table->id();
            $table->foreignId('prenda_id')->comment('Prenda (prendas.id).')->constrained('prendas')->restrictOnDelete();
            $table->string('ruta_archivo', 255)->comment('Ruta en el disco del tenant.');
            $table->enum('momento', ['ingreso', 'devolucion'])->comment('Cuándo se tomó la foto.');
            $table->unsignedBigInteger('registrado_por')->comment('users.id que subió la foto (sin FK).');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prenda_fotos');
    }
};
