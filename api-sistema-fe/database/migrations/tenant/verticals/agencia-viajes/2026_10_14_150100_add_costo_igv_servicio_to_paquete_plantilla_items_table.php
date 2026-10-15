<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_14_150100_add_costo_igv_servicio_to_paquete_plantilla_items_table.php
//
// Fila 13, fase 1a (plan-tours-venta-simple-costo-real.md §3.2, N3/N4/N5).
// paquete_plantilla_items pasa a ser el "Incluye" estructurado del tour: cada
// servicio con su costo estimado por persona, su tipo de IGV (según dónde se
// presta; vacío = el del tour) y un proveedor sugerido opcional.
// proveedor_tarifa_id/guia_tarifa_id se quedan, solo como referencia de la
// calculadora.
//
// destino_servicio_id (hallazgo de la sesión, aprobado 14-oct-2026): hasta hoy
// el servicio se deducía de la tarifa (tarifa → proveedor_servicio →
// destino_servicio), así que no podía existir un servicio del Incluye sin
// tarifa. Con esta columna un tour simple puede tener un servicio con el costo
// escrito a mano. Backfill desde la tarifa para los ítems existentes; el
// proveedor sugerido también se llena desde la tarifa (es el mismo dato).
// desdePlantilla() (cotizador) no cambia: ya salta los ítems sin tarifa.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paquete_plantilla_items', function (Blueprint $table) {
            $table->foreignId('destino_servicio_id')->nullable()->after('paquete_plantilla_hijo_id')->constrained('destino_servicio');
            $table->foreignId('proveedor_id')->nullable()->after('destino_servicio_id')->constrained('proveedores')->nullOnDelete(); // sugerido para la salida; opcional, se vacía si se borra el proveedor
            $table->decimal('costo_estimado_persona', 10, 2)->nullable()->after('proveedor_id');
            $table->decimal('costo_estimado_persona_ext', 10, 2)->nullable()->after('costo_estimado_persona'); // vacío = nacional
            $table->string('tip_afe_igv', 2)->nullable()->after('costo_estimado_persona_ext'); // vacío = el del tour
            $table->string('destino_tributario')->nullable()->after('tip_afe_igv');
        });

        DB::statement(<<<'SQL'
            update paquete_plantilla_items pi
            set destino_servicio_id = ps.destino_servicio_id,
                proveedor_id = ps.proveedor_id
            from proveedor_tarifas pt
            join proveedor_servicios ps on ps.id = pt.proveedor_servicio_id
            where pt.id = pi.proveedor_tarifa_id
        SQL);
    }

    public function down(): void
    {
        Schema::table('paquete_plantilla_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('destino_servicio_id');
            $table->dropConstrainedForeignId('proveedor_id');
            $table->dropColumn(['costo_estimado_persona', 'costo_estimado_persona_ext', 'tip_afe_igv', 'destino_tributario']);
        });
    }
};
