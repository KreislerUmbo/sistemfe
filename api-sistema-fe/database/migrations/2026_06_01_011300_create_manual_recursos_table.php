<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'manual_recursos' existe en Postgres pero nunca tuvo Schema::create en
// disco.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_recursos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sistema_id');
            $table->foreign('sistema_id')->references('id')->on('systems')->onDelete('cascade');
            $table->string('categoria', 50)->nullable();
            $table->string('titulo', 255);
            $table->text('descripcion')->nullable();
            $table->string('tipo_recurso', 50)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('archivo', 255)->nullable();
            $table->string('miniatura', 255)->nullable();
            $table->integer('orden')->default(0)->nullable();
            $table->smallInteger('destacado')->default(0)->nullable();
            $table->smallInteger('estado')->default(1)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_recursos');
    }
};
