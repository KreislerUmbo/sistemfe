<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'client_addresses' existe en Postgres pero nunca tuvo Schema::create en
// disco. Columnas replicadas 1:1 desde information_schema. NOTA: la tabla
// real no tiene deleted_at (no usa SoftDeletes) — se replica tal cual.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->foreign('client_id')->references('id')->on('clients');
            $table->string('nombre', 100)->nullable();
            $table->string('direccion', 100)->nullable();
            $table->string('departamento', 50)->nullable();
            $table->string('provincia', 50)->nullable();
            $table->string('distrito', 50)->nullable();
            $table->string('referencia', 200)->nullable();
            $table->string('principal', 150)->nullable();
            $table->string('state', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_addresses');
    }
};
