<?php

// T-052 (CRM C3): el resultado de la cotización. resultado_motivo_id guarda el motivo de la pérdida
// («Cerrar como perdida») o, opcional, el de la ganancia (al aceptar); perdida_en distingue los dos
// casos y es lo que lee Cotizacion::estadoResumen(). Instante en UTC, como todo instante.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->foreignId('resultado_motivo_id')->nullable()->constrained('crm_motivos_resultado');
            $table->string('resultado_detalle', 500)->nullable();
            $table->timestamp('perdida_en')->nullable()->comment('UTC; null = no está perdida');
            $table->foreignId('perdida_por')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resultado_motivo_id');
            $table->dropColumn(['resultado_detalle', 'perdida_en']);
            $table->dropConstrainedForeignId('perdida_por');
        });
    }
};
