<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_30_100000_retrofit_reserva_para_sesion_11c.php
//
// Sesión 11c del vertical Agencia de Viajes — retrofit sobre las tablas de
// Sesión 8 (ya mergeadas), confirmado limpio contra el schema real antes de
// escribir esto (0 filas reales en reserva_items/reserva_pasajeros en
// cualquier tenant — ver TODO.md, entrada "Prompt de Sesión 11c
// pre-verificado contra el schema real — 30-jul-2026").
//
// Sin doctrine/dbal instalado (confirmado: no está en vendor/) — los DROP
// NOT NULL van por SQL crudo, mismo patrón ya usado en
// database/migrations/tenant/core/2026_07_13_090200_fix_sales_relax_columns.php.
//
// reserva_items.proveedor_tarifa_id: retrofit ya confirmado en TODO.md
// ("Retrofit confirmado para Sesión 11c", 28-jul-2026) — mismo patrón que
// guia_id (Sesión 8a), reasignable en cualquier momento. Backfill posible
// porque reserva_items.alternativa_item_id es FK real y directa a
// alternativa_items.
//
// reserva_items.fecha: NOT NULL en el schema real (solo hora es nullable) —
// no siempre se sabe la fecha concreta del servicio al momento de aceptar
// la alternativa.
//
// reserva_pasajeros.nombre/.documento: NOT NULL en el schema real — el
// shell se crea vacío al aceptar la alternativa (ReservaController::
// crearReservaDesdeAlternativa()) y se completa después, mismo criterio que
// guia_id/proveedor_tarifa_id.
//
// reserva_pasajeros.tipo_pax: columna nueva, mismo dominio que
// cotizacion_pasajeros.tipo_pax (adulto/nino/infante) — se copia al crear
// cada shell. Sin backfill necesario, 0 filas reales hoy.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->foreignId('proveedor_tarifa_id')->nullable()->after('guia_id')->constrained('proveedor_tarifas');
        });

        DB::statement('
            UPDATE reserva_items ri
            SET proveedor_tarifa_id = ai.proveedor_tarifa_id
            FROM alternativa_items ai
            WHERE ai.id = ri.alternativa_item_id
              AND ai.proveedor_tarifa_id IS NOT NULL
        ');

        DB::statement('ALTER TABLE reserva_items ALTER COLUMN fecha DROP NOT NULL');

        DB::statement('ALTER TABLE reserva_pasajeros ALTER COLUMN nombre DROP NOT NULL');
        DB::statement('ALTER TABLE reserva_pasajeros ALTER COLUMN documento DROP NOT NULL');

        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->string('tipo_pax')->nullable()->after('reserva_id');
        });
    }

    public function down(): void
    {
        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->dropColumn('tipo_pax');
        });

        DB::statement("UPDATE reserva_pasajeros SET nombre = '' WHERE nombre IS NULL");
        DB::statement("UPDATE reserva_pasajeros SET documento = '' WHERE documento IS NULL");
        DB::statement('ALTER TABLE reserva_pasajeros ALTER COLUMN nombre SET NOT NULL');
        DB::statement('ALTER TABLE reserva_pasajeros ALTER COLUMN documento SET NOT NULL');

        DB::statement("UPDATE reserva_items SET fecha = CURRENT_DATE WHERE fecha IS NULL");
        DB::statement('ALTER TABLE reserva_items ALTER COLUMN fecha SET NOT NULL');

        Schema::table('reserva_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proveedor_tarifa_id');
        });
    }
};
