<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_30_110500_add_dias_aviso_vencimiento_cotizacion_to_configuracion_agencia_table.php
//
// Sesión 11b4 — mismo patrón que dias_aviso_pago_proveedor (Sesión 2):
// default fijo en 2 (a diferencia de dias_vigencia_cotizacion/
// dias_limpieza_alternativas_descartadas, que el plan define sin default).
//
// NOTA DE ALCANCE: este campo y la fila nueva de tipos_recordatorio
// ('cotizacion_por_vencer', ver TipoRecordatorioSeeder) son la mitad
// "catálogo/config" del punto 9 del diseño. La mitad "disparador" (un job/
// comando que recorra alternativas.fecha_vencimiento y cree filas reales en
// `recordatorios`) NO se implementa en esta sesión — no existe ningún
// mecanismo de generación automática de recordatorios en el backend todavía
// (Sesión 10 dejó solo el schema/catálogo, sin controller/comando/servicio
// que lo dispare para NINGUNO de los 4 códigos automáticos existentes, no
// solo para este nuevo). Ver historial de
// plan-modulo-cotizaciones-reservas.md para el detalle completo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->unsignedSmallInteger('dias_aviso_vencimiento_cotizacion')->default(2)->after('dias_aviso_pago_proveedor');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->dropColumn('dias_aviso_vencimiento_cotizacion');
        });
    }
};
