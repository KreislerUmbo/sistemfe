<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_11_01_100000_add_montos_por_pasajero_to_cotizacion_pasaje_aereo.php
//
// T-076: los cargos y el fee de un pasaje aéreo pasan a ser POR PASAJERO. Los pasajes ya guardados
// sumaban cargos y fee una sola vez por boleto y no deben cambiar de precio: esta marca decide la
// fórmula. false (el valor de todos los existentes) = fórmula vieja; los nuevos nacen con true.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_pasaje_aereo', function (Blueprint $table) {
            $table->boolean('montos_por_pasajero')->default(false)
                ->comment('true = cargos y fee se multiplican por los pasajeros marcados; false = pasaje anterior a T-076 (montos del grupo, una vez)');
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_pasaje_aereo', function (Blueprint $table) {
            $table->dropColumn('montos_por_pasajero');
        });
    }
};
