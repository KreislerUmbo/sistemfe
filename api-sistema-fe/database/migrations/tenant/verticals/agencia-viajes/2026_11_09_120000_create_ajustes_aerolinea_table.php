<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_11_09_120000_create_ajustes_aerolinea_table.php
//
// T-016 — Registro de lo que corrige `agencia-viajes:normalizar-aerolineas`: cada nombre de
// aerolínea reemplazado por el del catálogo, para poder revertirlo con --revertir. No hay FK
// a las tablas corregidas (es un registro histórico).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_aerolinea', function (Blueprint $table) {
            $table->id();
            $table->string('tabla', 100);
            $table->unsignedBigInteger('registro_id');
            $table->string('columna', 100);
            $table->string('antes');
            $table->string('despues');
            $table->timestamp('revertido_at')->nullable();
            $table->timestamps();

            $table->index(['tabla', 'registro_id', 'columna']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_aerolinea');
    }
};
