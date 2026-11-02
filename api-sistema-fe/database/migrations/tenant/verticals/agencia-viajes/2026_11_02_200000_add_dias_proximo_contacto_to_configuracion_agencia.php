<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_11_02_200000_add_dias_proximo_contacto_to_configuracion_agencia.php
//
// T-051 (CRM C2): al enviar una cotización se propone "Llamar en N días". 2 para todas las
// agencias existentes; se cambia en Configuración de Agencia.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->unsignedSmallInteger('dias_proximo_contacto')->default(2)
                ->comment('Días después del envío para la próxima acción propuesta ("Llamar")');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->dropColumn('dias_proximo_contacto');
        });
    }
};
