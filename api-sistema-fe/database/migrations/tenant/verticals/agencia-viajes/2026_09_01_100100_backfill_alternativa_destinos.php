<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_100100_backfill_alternativa_destinos.php
//
// Sesión 12b — crea exactamente 1 fila en alternativa_destinos por cada
// Alternativa existente, derivada de su Cotizacion padre. Migración
// separada de la que crea la tabla (mismo patrón que usó 11r para
// reserva.fecha_viaje_desde/hasta: 1 create + 1 backfill).
//
// destino_atractivo_id se resuelve con match case-insensitive + trim
// contra destinos_atractivos.nombre (mismo criterio que el fix de
// duplicados de ServicioController, no un criterio nuevo) — si no hay
// match, queda null y destino_texto conserva el valor original completo,
// nunca se pierde el dato. Si el texto matchea más de un destino_atractivo
// (nombres duplicados en el catálogo), se toma el de menor id de forma
// determinística — no se ha observado ese caso en datos reales, pero no
// se quiere dejar el resultado del backfill a la suerte del orden físico
// de Postgres.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $alternativas = DB::table('alternativas')
            ->join('cotizaciones', 'cotizaciones.id', '=', 'alternativas.cotizacion_id')
            ->select('alternativas.id as alternativa_id', 'cotizaciones.destino', 'cotizaciones.fecha_viaje_desde', 'cotizaciones.fecha_viaje_hasta')
            ->get();

        foreach ($alternativas as $alternativa) {
            $destinoAtractivoId = null;

            if ($alternativa->destino !== null && trim($alternativa->destino) !== '') {
                $match = DB::table('destinos_atractivos')
                    ->whereRaw('LOWER(TRIM(nombre)) = LOWER(TRIM(?))', [$alternativa->destino])
                    ->orderBy('id')
                    ->first();

                $destinoAtractivoId = $match->id ?? null;
            }

            DB::table('alternativa_destinos')->insert([
                'alternativa_id' => $alternativa->alternativa_id,
                'destino_atractivo_id' => $destinoAtractivoId,
                'destino_texto' => $alternativa->destino,
                'orden' => 1,
                'fecha_inicio' => $alternativa->fecha_viaje_desde,
                'fecha_fin' => $alternativa->fecha_viaje_hasta,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('alternativa_destinos')->truncate();
    }
};
