<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_27_090000_add_vuelo_agencia_to_reserva_item_pasajero_table.php
//
// Auditoría de UX/funcionalidad del módulo de Reservas (2026-08-27). Cierra
// un gap real: el vuelo que la AGENCIA vende (alternativa_item.origen_tipo=
// 'pasaje_aereo', vía CotizacionPasajeAereo) nunca tuvo dónde guardar el
// número de vuelo/hora confirmados — solo existían para el caso "el
// pasajero compra el vuelo por su cuenta" (reserva_pasajeros.vuelo_*,
// migración 2026_07_28_110100_create_reserva_pasajeros_table.php +
// 2026_08_13_090000_add_vuelo_fecha_to_reserva_pasajeros_table.php).
// ReporteOperativoController::armarFila() pegaba ese dato de "cuenta
// propia" en TODAS las filas del pasajero sin importar el servicio,
// mezclando conceptualmente ambos casos.
//
// Decisión de diseño (discutida con el usuario, varias iteraciones):
// - NO va en CotizacionPasajeAereo — en cotización no hay certeza de
//   aerolínea/número de vuelo, a lo mucho fecha/hora estimada.
// - NO va 1-a-1 con reserva_items — un mismo ítem de vuelo cotizado junto
//   puede terminar con pasajeros en vuelos reales distintos tras la
//   compra (grupos que se fragmentan por disponibilidad de asientos),
//   caso real en agencias de viaje.
// - Va POR PASAJERO, en reserva_item_pasajero — la tabla puente que ya
//   vincula pasajero↔ítem y ya tiene precedente de dato operativo
//   por-pasajero (checkin_realizado/checkin_hora, migración
//   2026_07_28_160000_add_checkin_to_reserva_item_pasajero_table.php).
//   Mismo mecanismo de "materializar todos los pasajeros del ítem al
//   primer edit" que ya usa ReservaItemPasajeroController::checkin()
//   cuando el ítem todavía no tiene ningún vínculo específico.
//
// fecha_ida/fecha_vuelta (no solo hora): mismo criterio que
// reserva_pasajeros.vuelo_fecha_ida/vuelta — un vuelo de ida y uno de
// vuelta pueden caer en fechas muy distintas dentro del mismo viaje, no
// alcanza con una sola hora sin fecha propia.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_item_pasajero', function (Blueprint $table) {
            $table->string('vuelo_numero_ida')->nullable();
            $table->date('vuelo_fecha_ida')->nullable();
            $table->time('vuelo_hora_ida')->nullable();
            $table->string('vuelo_numero_vuelta')->nullable();
            $table->date('vuelo_fecha_vuelta')->nullable();
            $table->time('vuelo_hora_vuelta')->nullable();
            // Si queda vacía, se asume la aerolínea tentativa cotizada en
            // CotizacionPasajeAereo — ver ReservaItemPasajeroController.
            $table->string('vuelo_aerolinea_confirmada')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reserva_item_pasajero', function (Blueprint $table) {
            $table->dropColumn([
                'vuelo_numero_ida', 'vuelo_fecha_ida', 'vuelo_hora_ida',
                'vuelo_numero_vuelta', 'vuelo_fecha_vuelta', 'vuelo_hora_vuelta',
                'vuelo_aerolinea_confirmada',
            ]);
        });
    }
};
