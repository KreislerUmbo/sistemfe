<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_09_120000_add_tarifa_valida_hasta_to_cotizacion_pasaje_aereo_table.php
//
// Vigencia de la tarifa aérea (09-oct-2026, pedido del usuario): la
// aerolínea sostiene la tarifa solo hasta una fecha Y HORA límite (a veces
// el mismo día) — los "N días desde la emisión" de configuracion_agencia no
// sirven para un pasaje. Instante real → se guarda en UTC y se muestra en
// hora de Perú (regla única de fechas, CLAUDE.md). Nullable: los pasajes ya
// cotizados no tienen el dato y el PDF sigue mostrando la vigencia de
// siempre para ellos.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_pasaje_aereo', function (Blueprint $table) {
            $table->timestamp('tarifa_valida_hasta')->nullable()->after('fecha_cotizado');
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_pasaje_aereo', function (Blueprint $table) {
            $table->dropColumn('tarifa_valida_hasta');
        });
    }
};
