<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_19_090000_add_reprogramacion_a_reserva_table.php
//
// Fase 2 del fix Cotización↔Reserva (brief "Fix fechas Cotización↔Reserva,
// FASE 2 (reprogramación)" — 19-ago-2026). Sigue a la Fase 1 (2026-08-18,
// reserva.fecha_viaje_desde/hasta + reserva_items.fecha_origen).
//
// Columnas de auditoría SIMPLE (decisión de alcance ya tomada, no
// reabrir): campos planos en `reserva`, no una tabla `reserva_
// reprogramaciones` aparte. Si la reserva se reprograma más de una vez,
// solo queda visible la reprogramación MÁS RECIENTE — mismo trade-off ya
// aceptado que `fecha_cancelacion`/`motivo_cancelacion` en esta misma
// tabla. Si en el futuro hace falta el historial completo, se migra a
// tabla aparte entonces, no antes.
//
// Sin backfill — nacen vacías, se llenan solo cuando ocurre la primera
// reprogramación real de cada reserva.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva', function (Blueprint $table) {
            $table->date('fecha_viaje_desde_original')->nullable()->after('fecha_viaje_hasta');
            $table->date('fecha_viaje_hasta_original')->nullable()->after('fecha_viaje_desde_original');
            $table->timestamp('fecha_reprogramacion')->nullable()->after('fecha_viaje_hasta_original');
            $table->string('motivo_reprogramacion')->nullable()->after('fecha_reprogramacion');
        });
    }

    public function down(): void
    {
        Schema::table('reserva', function (Blueprint $table) {
            $table->dropColumn(['fecha_viaje_desde_original', 'fecha_viaje_hasta_original', 'fecha_reprogramacion', 'motivo_reprogramacion']);
        });
    }
};
