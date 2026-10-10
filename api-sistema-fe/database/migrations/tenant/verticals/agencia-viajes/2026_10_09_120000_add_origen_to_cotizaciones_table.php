<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_09_120000_add_origen_to_cotizaciones_table.php
//
// Venta Directa, bloque 1 (09-oct-2026): VentaDirectaController::store() arma
// una Cotizacion real como soporte interno de la reserva, pero hasta ahora no
// había forma de distinguirla de una cotización comercial — aparecía en el
// listado del Cotizador como "reservada" e inflaba las cotizaciones creadas/
// conversión del Inicio, y CodigoGeneradorService::generarParaReserva()
// derivaba su código igual que el de una cotización (VDKM-1026-0000015 y
// CDKM-1026-0000015 daban los dos RDKM-1026-0000015, reserva.codigo es
// unique). 'cotizador' | 'venta_directa'.
//
// Backfill: el origen solo se puede reconocer por el prefijo con que se
// generó el código — 'VD' (hardcodeado antes del Módulo 12) o el prefijo
// vigente de venta_directa en configuracion_codigos.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->string('origen', 20)->default('cotizador')->index();
        });

        $prefijos = array_values(array_unique(array_filter([
            'VD',
            DB::table('configuracion_codigos')->where('tipo', 'venta_directa')->value('prefijo'),
        ])));

        DB::table('cotizaciones')->whereIn('codigo_prefijo', $prefijos)->update(['origen' => 'venta_directa']);
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
