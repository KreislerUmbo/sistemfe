<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_22_100000_corregir_exonerado_nacional_a_amazonia.php
//
// Fila 13 (22-oct-2026, decidido con el usuario): en Agencia de Viajes un
// servicio solo está exonerado porque se presta en la Amazonía (Ley 27037).
// "Exonerado (20) + destino nacional" era un dato mal cargado: el formulario
// de la tarifa dejaba elegir las dos listas por separado. Esta migración lo
// corrige a "20 + amazonia" SOLO en tablas de Agencia (las de retail —
// products, sales, sale_details— no se tocan: ahí 20 + nacional es válido).
//
// Para SUNAT no cambia nada (el código sigue siendo 20; los comprobantes ya
// emitidos no se tocan). Cambia la etiqueta interna: esos servicios muestran
// la leyenda de la Ley 27037 y piden la confirmación Amazonía antes de
// facturar. Cada fila cambiada queda en ajustes_exonerado_amazonia, y down()
// la devuelve a 'nacional'. Desde ahora la regla la aplican los modelos
// (Concerns\ExoneradoEsAmazonia) y las pantallas.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** tabla => [columna IGV, columna destino] */
    private const TABLAS = [
        'proveedor_tarifas' => ['tip_afe_igv', 'destino_tributario'],
        'opciones_hotel_tarifas' => ['tip_afe_igv', 'destino_tributario'],
        'paquetes_plantilla' => ['tip_afe_igv', 'destino_tributario'],
        'paquete_plantilla_items' => ['tip_afe_igv', 'destino_tributario'],
        'alternativa_items' => ['tip_afe_igv', 'destino_tributario'],
        'reserva_items' => ['tip_afe_igv', 'destino_tributario'],
        'configuracion_agencia' => ['tip_afe_igv_default', 'destino_tributario_default'],
    ];

    public function up(): void
    {
        Schema::create('ajustes_exonerado_amazonia', function (Blueprint $table) {
            $table->id();
            $table->string('tabla');
            $table->unsignedBigInteger('fila_id');
            $table->timestamp('created_at')->useCurrent();
        });

        foreach (self::TABLAS as $tabla => [$igv, $destino]) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $destino)) {
                continue;
            }

            $ids = DB::table($tabla)->where($igv, '20')->where($destino, 'nacional')->pluck('id');
            foreach ($ids as $id) {
                DB::table('ajustes_exonerado_amazonia')->insert(['tabla' => $tabla, 'fila_id' => $id]);
            }
            DB::table($tabla)->whereIn('id', $ids)->update([$destino => 'amazonia']);
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla => [, $destino]) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }
            $ids = DB::table('ajustes_exonerado_amazonia')->where('tabla', $tabla)->pluck('fila_id');
            DB::table($tabla)->whereIn('id', $ids)->update([$destino => 'nacional']);
        }

        Schema::dropIfExists('ajustes_exonerado_amazonia');
    }
};
