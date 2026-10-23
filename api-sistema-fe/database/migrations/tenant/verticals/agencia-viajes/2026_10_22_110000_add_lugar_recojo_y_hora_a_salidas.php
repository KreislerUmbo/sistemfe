<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_22_110000_add_lugar_recojo_y_hora_a_salidas.php
//
// Fila 13, fase 2c (22-oct-2026, pedido del usuario): el tour ya tiene su hora
// (paquetes_plantilla.hora_salida) y su lugar de recojo
// (paquetes_plantilla.lugar_recojo), pero nadie los llevaba a la salida (0 de
// 14 salidas con hora en agencia-demo). Desde ahora la salida nace con los
// dos datos del tour (SalidaEngancheService) y se pueden cambiar en la salida;
// reserva_items.hora queda como "hora de recojo" de esa reserva.
//
// - salidas_operativas.lugar_recojo (mismo nombre que en el tour).
// - Salidas existentes: toman la hora y el lugar de recojo de su tour, solo
//   donde están vacíos (nunca pisa un dato puesto a mano). Eso no se revierte
//   en down(): es el dato del propio tour y no se distingue de uno puesto
//   después a mano.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salidas_operativas', function (Blueprint $table) {
            $table->string('lugar_recojo')->nullable()->after('hora');
        });

        DB::statement('UPDATE salidas_operativas s SET hora = p.hora_salida
            FROM paquetes_plantilla p
            WHERE s.tour_origen_id = p.id AND s.hora IS NULL AND p.hora_salida IS NOT NULL');
        DB::statement("UPDATE salidas_operativas s SET lugar_recojo = p.lugar_recojo
            FROM paquetes_plantilla p
            WHERE s.tour_origen_id = p.id AND s.lugar_recojo IS NULL AND p.lugar_recojo IS NOT NULL AND p.lugar_recojo <> ''");
    }

    public function down(): void
    {
        Schema::table('salidas_operativas', function (Blueprint $table) {
            $table->dropColumn('lugar_recojo');
        });
    }
};
