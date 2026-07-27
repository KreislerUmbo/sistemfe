<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'categories' existe en Postgres pero nunca tuvo Schema::create en disco.
// Columnas replicadas 1:1 desde information_schema (auditoría 2026-07-12).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('title', 250);
            $table->string('imagen', 250)->nullable();
            $table->smallInteger('state')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
