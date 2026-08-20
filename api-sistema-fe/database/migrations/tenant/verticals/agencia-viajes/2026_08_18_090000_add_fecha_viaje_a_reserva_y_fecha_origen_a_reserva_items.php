<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_18_090000_add_fecha_viaje_a_reserva_y_fecha_origen_a_reserva_items.php
//
// Fase 1 del fix Cotización↔Reserva (brief "Fix fechas Cotización↔Reserva,
// FASE 1" — 18-ago-2026). Cierra el bug de fondo: reserva_items.fecha se
// calculaba a partir de cotizacion.fecha_viaje_desde EN VIVO, pero la
// cotización sigue siendo editable sin ningún guard después de aceptada —
// cualquier edición posterior desincronizaba en silencio la fecha operativa
// ya congelada en los ítems de la reserva. `reserva` gana sus propias
// fecha_viaje_desde/fecha_viaje_hasta, copiadas UNA SOLA VEZ al aceptar la
// alternativa (ver ReservaController::crearReservaDesdeAlternativa()) —
// de ahí en adelante la reserva es la fuente de verdad para su propia
// fecha, la cotización queda libre para seguir editándose sin arrastrar
// nada.
//
// fecha_viaje_hasta NUNCA alimentó el cálculo de reserva_items.fecha (solo
// dia_referencial + fecha_viaje_desde lo hacen, ver
// ReservaController::crearReservaItemDesdeAlternativaItem()) — no hay
// ninguna señal en los datos existentes para "inferir" un hasta corregido
// como sí se puede inferir un desde a partir de los ítems. El backfill de
// hasta es siempre mejor esfuerzo (valor actual de la cotización), en
// cualquier categoría.
//
// reserva_items.fecha_origen ('auto' | 'manual'): distingue una fecha
// calculada por la fórmula de una editada a mano
// (ReservaItemController::update()) — sin esto, cualquier recálculo futuro
// (Fase 2, reprogramación) no tendría forma de saber qué ítems NO debe
// tocar. Backfill: todo lo existente nace 'auto' — no hay forma
// retroactiva de saber cuáles de los reserva_items ya persistidos fueron
// en realidad editados a mano antes de esta migración (trade-off aceptado
// explícitamente, ver fila 11r de plan-hoja-de-ruta-ejecucion.md). Casos
// reales confirmados con
// `php artisan agencia-viajes:diagnosticar-fechas-reserva` antes de correr
// esto: 5 reservas en el único tenant real con el vertical activo
// (agencia-demo), 2 CONSISTENTE, 1 AMBIGUA, 1 DIVERGENTE, 1 SIN_FECHA —
// confirmadas por el usuario como datos de prueba descartables, no
// reservas de negocio reales.
//
// Backfill de fecha_viaje_desde, por reserva — MISMO algoritmo de
// clasificación que el comando de diagnóstico (ver
// app/Console/Commands/DiagnosticarFechasReserva.php, que debe
// interpretarse como la referencia canónica de este algoritmo — si
// alguna vez divergen, es un bug):
//   1. Para cada reserva_item con dia_referencial resoluble (vía su
//      alternativa_item) Y fecha ya poblada, infiere
//      fecha_base = reserva_item.fecha - (dia_referencial - 1) días.
//   2. Ninguna base inferible (SIN_FECHA) → usa cotizacion.fecha_viaje_desde
//      actual (mejor esfuerzo, no hay otra fuente).
//   3. Una sola base, distinta entre todos los ítems (AMBIGUA) → usa esa
//      base inferida, NO la cotización actual (es la fecha con la que la
//      reserva realmente operó).
//   4. Más de una base distinta entre los ítems de la misma reserva
//      (DIVERGENTE) → mejor esfuerzo (cotización actual), sin poder elegir
//      automáticamente cuál de las bases es la correcta.
//   5. Una sola base que además coincide con la cotización actual
//      (CONSISTENTE) → sin ambigüedad, incluida en el `log` de todas
//      formas para trazabilidad, no como caso a revisar.
//
// Los ids de reserva de las categorías AMBIGUA/DIVERGENTE/SIN_FECHA quedan
// registrados en storage/logs (Log::warning) al final de la migración —
// no bloquea la migración, pero no se esconden tampoco.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva', function (Blueprint $table) {
            $table->date('fecha_viaje_desde')->nullable()->after('estado');
            $table->date('fecha_viaje_hasta')->nullable()->after('fecha_viaje_desde');
        });

        Schema::table('reserva_items', function (Blueprint $table) {
            // Sin enum real de Postgres a propósito — mismo criterio ya usado
            // en el resto del proyecto para columnas de dominio cerrado pero
            // chico (ej. reserva.estado, alternativa.estado): string simple,
            // el dominio se documenta y valida en aplicación.
            $table->string('fecha_origen')->default('auto')->after('fecha');
        });

        $this->backfillFechaViajeReserva();

        DB::table('reserva_items')->update(['fecha_origen' => 'auto']);
    }

    public function down(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->dropColumn('fecha_origen');
        });

        Schema::table('reserva', function (Blueprint $table) {
            $table->dropColumn(['fecha_viaje_desde', 'fecha_viaje_hasta']);
        });
    }

    private function backfillFechaViajeReserva(): void
    {
        $reservas = DB::table('reserva')
            ->join('alternativas', 'alternativas.id', '=', 'reserva.alternativa_id')
            ->join('cotizaciones', 'cotizaciones.id', '=', 'alternativas.cotizacion_id')
            ->select(
                'reserva.id as reserva_id',
                'cotizaciones.fecha_viaje_desde as cot_desde',
                'cotizaciones.fecha_viaje_hasta as cot_hasta'
            )
            ->get();

        $ambiguas = [];
        $divergentes = [];
        $sinFecha = [];
        $consistentes = [];

        foreach ($reservas as $reserva) {
            $items = DB::table('reserva_items')
                ->join('alternativa_items', 'alternativa_items.id', '=', 'reserva_items.alternativa_item_id')
                ->where('reserva_items.reserva_id', $reserva->reserva_id)
                ->select('reserva_items.fecha as item_fecha', 'alternativa_items.dia_referencial')
                ->get();

            $bases = [];
            foreach ($items as $item) {
                if ($item->dia_referencial === null || $item->item_fecha === null) {
                    continue;
                }

                $base = Carbon::parse($item->item_fecha)->subDays((int) $item->dia_referencial - 1)->toDateString();
                $bases[$base] = true;
            }

            $basesUnicas = array_keys($bases);
            $cotDesdeStr = $reserva->cot_desde ? Carbon::parse($reserva->cot_desde)->toDateString() : null;

            if (count($basesUnicas) === 0) {
                $fechaDesde = $cotDesdeStr;
                $sinFecha[] = $reserva->reserva_id;
            } elseif (count($basesUnicas) > 1) {
                $fechaDesde = $cotDesdeStr;
                $divergentes[] = $reserva->reserva_id;
            } elseif ($basesUnicas[0] === $cotDesdeStr) {
                $fechaDesde = $basesUnicas[0];
                $consistentes[] = $reserva->reserva_id;
            } else {
                $fechaDesde = $basesUnicas[0];
                $ambiguas[] = $reserva->reserva_id;
            }

            DB::table('reserva')->where('id', $reserva->reserva_id)->update([
                'fecha_viaje_desde' => $fechaDesde,
                // Mejor esfuerzo siempre, ver comentario de cabecera del
                // archivo — hasta nunca fue insumo del cálculo de
                // reserva_items.fecha, no hay nada que inferir.
                'fecha_viaje_hasta' => $reserva->cot_hasta
                    ? Carbon::parse($reserva->cot_hasta)->toDateString()
                    : null,
            ]);
        }

        if (! empty($ambiguas) || ! empty($divergentes) || ! empty($sinFecha)) {
            Log::warning('[fix-fechas-cotizacion-reserva] Backfill de reserva.fecha_viaje_desde/hasta con casos que requieren revisión manual.', [
                'ambiguas_uso_fecha_inferida' => $ambiguas,
                'divergentes_uso_cotizacion_actual' => $divergentes,
                'sin_fecha_uso_cotizacion_actual' => $sinFecha,
                'consistentes' => $consistentes,
            ]);
        }
    }
};
