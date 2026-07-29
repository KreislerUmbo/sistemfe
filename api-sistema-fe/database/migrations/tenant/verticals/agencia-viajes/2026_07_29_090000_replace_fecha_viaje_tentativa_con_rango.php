<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_29_090000_replace_fecha_viaje_tentativa_con_rango.php
//
// RETROFIT 28/29-jul-2026 sobre Sesión 11b (cotizador): fecha_viaje_tentativa
// (una sola fecha) no alcanza para cotizar con fecha de ida y vuelta —
// reemplazada por fecha_viaje_desde/fecha_viaje_hasta, ambas nullable.
//
// Confirmado antes de escribir esta migración (no asumido): ningún tenant
// real (sandbox/umbo/negocio2/umbo-archivado) tiene todavía la tabla
// `cotizaciones` — la rama feature/sesion-11b-cotizador sigue sin mergear.
// El backfill de abajo es defensivo (por si se corre sobre un tenant de
// prueba que ya haya cargado alguna fila con fecha_viaje_tentativa), no
// porque haya datos reales conocidos que proteger.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->date('fecha_viaje_desde')->nullable()->after('destino');
            $table->date('fecha_viaje_hasta')->nullable()->after('fecha_viaje_desde');
        });

        DB::table('cotizaciones')
            ->whereNotNull('fecha_viaje_tentativa')
            ->update(['fecha_viaje_desde' => DB::raw('fecha_viaje_tentativa')]);

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('fecha_viaje_tentativa');
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->date('fecha_viaje_tentativa')->nullable()->after('destino');
        });

        DB::table('cotizaciones')
            ->whereNotNull('fecha_viaje_desde')
            ->update(['fecha_viaje_tentativa' => DB::raw('fecha_viaje_desde')]);

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn(['fecha_viaje_desde', 'fecha_viaje_hasta']);
        });
    }
};
