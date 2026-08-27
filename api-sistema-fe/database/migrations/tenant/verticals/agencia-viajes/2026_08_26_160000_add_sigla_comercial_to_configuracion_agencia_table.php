<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_26_160000_add_sigla_comercial_to_configuracion_agencia_table.php
//
// Módulo 12 — plan-modulo-codigos-numeracion.md §6.1: la sigla de la
// agencia (ej. "DKM") se configura una sola vez acá, a nivel de empresa/
// tenant — configuracion_codigos (próxima migración) solo la LEE para
// sugerir el prefijo de cada tipo de documento, no la duplica.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->string('sigla_comercial')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->dropColumn('sigla_comercial');
        });
    }
};
