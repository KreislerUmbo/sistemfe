<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_11_03_200100_copiar_checkins_a_reserva_item_checkins.php
//
// T-041 (H30): copia a reserva_item_checkins cada check-in ya marcado en
// reserva_item_pasajero, con su hora. Las asignaciones que el check-in viejo
// creó para "todos" NO se tocan (decisión H30, opción a). Las columnas viejas
// (checkin_realizado, checkin_hora) quedan sin uso y se quitan en una
// migración aparte, cuando esto ya esté en producción.
//
// Idempotente (ON CONFLICT DO NOTHING): correrla dos veces no duplica.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO reserva_item_checkins (reserva_item_id, reserva_pasajero_id, checkin_hora, created_at, updated_at)
            SELECT reserva_item_id, reserva_pasajero_id,
                   COALESCE(checkin_hora, updated_at, CURRENT_TIMESTAMP),
                   CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM reserva_item_pasajero
            WHERE checkin_realizado = true
            ON CONFLICT (reserva_item_id, reserva_pasajero_id) DO NOTHING
            SQL);
    }

    // Sin vuelta atrás: las columnas viejas siguen con sus datos.
    public function down(): void
    {
    }
};
