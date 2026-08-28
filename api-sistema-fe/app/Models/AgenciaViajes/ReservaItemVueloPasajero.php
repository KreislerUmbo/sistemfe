<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Vuelo vendido por la AGENCIA, por pasajero — migración
// 2026_08_27_110000_create_reserva_item_vuelo_pasajero_table. Tabla propia,
// SIN relación con reserva_item_pasajero (esa es del checkbox del tab
// "Asignación pasajero↔ítem", un concepto de agrupación de facturación/
// reporte sin ninguna relación con el vuelo — compartir la fila fue el bug
// real encontrado en pruebas: desmarcar un pasajero en Asignación borraba
// el vuelo ya cargado). Cada fila es independiente por (reserva_item,
// reserva_pasajero) — sin necesidad de "materializar a todos primero" como
// sí requiere reserva_item_pasajero.checkin_*.
//
// Sin cast 'date' en vuelo_fecha_ida/vuelta a propósito — mismo criterio
// que ReservaPasajero.vuelo_fecha_ida/vuelta (sin cast ahí tampoco): así
// llega como string plano 'Y-m-d' desde Postgres, sin el problema de
// timestamp ISO completo que si tuviera cast 'date' rompería <input
// type="date"> en el frontend sin truncar a mano (mismo bug ya documentado
// para reserva_items.fecha).
class ReservaItemVueloPasajero extends Model
{
    protected $table = 'reserva_item_vuelo_pasajero';

    protected $fillable = [
        'reserva_item_id',
        'reserva_pasajero_id',
        'vuelo_numero_ida',
        'vuelo_fecha_ida',
        'vuelo_hora_ida',
        'vuelo_numero_vuelta',
        'vuelo_fecha_vuelta',
        'vuelo_hora_vuelta',
        'vuelo_aerolinea_confirmada',
    ];

    public function reservaItem()
    {
        return $this->belongsTo(ReservaItem::class, 'reserva_item_id');
    }

    public function reservaPasajero()
    {
        return $this->belongsTo(ReservaPasajero::class, 'reserva_pasajero_id');
    }
}
