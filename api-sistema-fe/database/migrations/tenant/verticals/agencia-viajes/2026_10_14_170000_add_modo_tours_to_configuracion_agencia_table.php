<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_14_170000_add_modo_tours_to_configuracion_agencia_table.php
//
// Fila 13, fase 1b (plan-tours-venta-simple-costo-real.md §5b, D3 del brief).
// Interruptor por tenant de cómo se CARGA un tour en la cotización:
// 'clasico' = desdePlantilla() desarma el tour en servicios (como siempre);
// 'nuevo' = el tour entra como línea propia (TourLineaService). Solo lo cambia
// el Super-Admin (soporte). Todos los tenants nacen y quedan en 'clasico'.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->string('modo_tours')->default('clasico'); // 'clasico' | 'nuevo'
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->dropColumn('modo_tours');
        });
    }
};
