<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_03_090000_add_descuento_config_to_configuracion_agencia_table.php
//
// Sesión 11i — configuración de descuento por agencia (% o monto), tanto
// por ítem del lienzo como global del resumen. Mismo patrón de retrofit ya
// usado en esta tabla singleton (ver 2026_07_30_110500_...): ALTER con
// default, sin re-insertar la fila (la fila existente hereda el default).
//
// permitir_descuento_item=false apaga el lenguaje de "descuento" en el
// lienzo por completo (el vendedor edita precio_convertido directo, sin
// que se le muestre % ni monto) — modo_descuento_item solo importa cuando
// permitir_descuento_item=true.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->boolean('permitir_descuento_item')->default(true)->after('dias_aviso_vencimiento_cotizacion');
            $table->string('modo_descuento_item')->default('porcentaje')->after('permitir_descuento_item'); // 'porcentaje' | 'monto'
            $table->string('modo_descuento_global')->default('porcentaje')->after('modo_descuento_item'); // 'porcentaje' | 'monto'
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->dropColumn(['permitir_descuento_item', 'modo_descuento_item', 'modo_descuento_global']);
        });
    }
};
