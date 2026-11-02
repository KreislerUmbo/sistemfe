<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_11_01_200000_add_moneda_cotizacion_default_to_configuracion_agencia.php
//
// T-033: moneda con la que nace la primera alternativa de una cotización nueva (la "Alternativa A"
// automática). PEN para todas las agencias existentes; se cambia en Configuración de Agencia.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->string('moneda_cotizacion_default', 3)->default('PEN')
                ->comment('PEN|USD — moneda prellenada en Nueva cotización');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->dropColumn('moneda_cotizacion_default');
        });
    }
};
