<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_14_150000_add_precio_costo_uso_to_paquetes_plantilla_table.php
//
// Fila 13, fase 1a (plan-tours-venta-simple-costo-real.md §3.1, N1/N2/N5/N15).
// El tour pasa a tener precio por persona propio (adulto/niño × nacional/
// extranjero), costo estimado por persona, moneda, IGV por defecto, modalidad,
// mínimo para salir y "uso". Solo aditiva (§5b): precio_venta_final se
// reutiliza como precio adulto nacional, sin renombrarlo ni transformarlo.
//
// Backfill de `uso` (N15): un tour vinculado a una opción de mayorista
// (opcion_mayorista_tours) y sin precio solo sirve de itinerario; el resto
// queda 'venta'. Campo explícito, no deducido de "no tiene precio", para no
// confundir un tour nuevo aún sin precio con uno de mayorista.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paquetes_plantilla', function (Blueprint $table) {
            $table->decimal('precio_venta_nino', 10, 2)->nullable()->after('precio_venta_final'); // vacío = adulto
            $table->decimal('precio_venta_adulto_ext', 10, 2)->nullable()->after('precio_venta_nino'); // vacío = nacional
            $table->decimal('precio_venta_nino_ext', 10, 2)->nullable()->after('precio_venta_adulto_ext');
            $table->decimal('costo_estimado_persona', 10, 2)->nullable()->after('precio_venta_nino_ext'); // a mano; si todo el Incluye tiene costo, manda la suma
            $table->decimal('costo_estimado_persona_ext', 10, 2)->nullable()->after('costo_estimado_persona');
            $table->string('moneda', 3)->default('PEN')->after('costo_estimado_persona_ext'); // 'PEN' | 'USD'
            $table->string('tip_afe_igv', 2)->nullable()->after('moneda'); // IGV por defecto del tour (Catálogo 07)
            $table->string('destino_tributario')->nullable()->after('tip_afe_igv'); // 'amazonia' | 'nacional' | 'extranjero'
            $table->string('modalidad')->default('compartido')->after('destino_tributario'); // 'compartido' | 'privado'
            $table->unsignedSmallInteger('pax_minimo_salida')->nullable()->after('modalidad');
            $table->string('uso')->default('venta')->after('pax_minimo_salida'); // 'venta' | 'solo_itinerario'
        });

        DB::table('paquetes_plantilla')
            ->whereNull('precio_venta_final')
            ->whereIn('id', DB::table('opcion_mayorista_tours')->whereNotNull('paquete_plantilla_id')->select('paquete_plantilla_id'))
            ->update(['uso' => 'solo_itinerario']);
    }

    public function down(): void
    {
        Schema::table('paquetes_plantilla', function (Blueprint $table) {
            $table->dropColumn([
                'precio_venta_nino', 'precio_venta_adulto_ext', 'precio_venta_nino_ext',
                'costo_estimado_persona', 'costo_estimado_persona_ext', 'moneda',
                'tip_afe_igv', 'destino_tributario', 'modalidad', 'pax_minimo_salida', 'uso',
            ]);
        });
    }
};
