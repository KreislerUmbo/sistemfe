<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_28_090000_add_tratamiento_tributario_to_alternativa_items_table.php
//
// Análisis de impuestos del vertical (28-ago-2026): el único punto donde
// tip_afe_igv/destino_tributario es obligatorio y confiable es
// proveedor_tarifas — de ahí en adelante el dato se pierde (ni
// alternativa_items ni reserva_items lo tenían, y los orígenes
// manual/mayorista/guia/pasaje_aereo nunca tuvieron campo tributario
// propio). Esta migración agrega las 2 columnas acá; el copiado real
// (desde proveedor_tarifa cuando existe, o desde el default de
// configuracion_agencia cuando no) vive en AlternativaItemController.
// Nullable a propósito: registros creados antes de este fix quedan en
// null, resueltos con fallback en ReservaFacturacionController — no se
// retrofitea data histórica.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->string('tip_afe_igv', 2)->nullable()->after('proveedor_promovido_id');
            $table->string('destino_tributario')->nullable()->after('tip_afe_igv');
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropColumn(['tip_afe_igv', 'destino_tributario']);
        });
    }
};
