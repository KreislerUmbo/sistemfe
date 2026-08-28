<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Copiado de la alternativa aceptada, con fecha/hora concretas —
// plan-modulo-cotizaciones-reservas.md §4. Tenant (sin CentralConnection).
// reserva_id/alternativa_item_id/guia_id/proveedor_tarifa_id llevan
// belongsTo real (FK dentro de la misma DB tenant). Costo/precio se leen
// vía alternativaItem(), no se duplican columnas acá.
//
// proveedor_tarifa_id: retrofit Sesión 11c (ver migración
// 2026_07_30_100000_retrofit_reserva_para_sesion_11c.php) — "quién opera"
// se confirma cerca de la fecha, reasignable en cualquier momento, igual
// que guia_id.
//
// fecha_origen ('auto'|'manual', Fase 1 del fix Cotización↔Reserva,
// 2026-08-18): 'auto' = fecha calculada por la fórmula
// (reserva.fecha_viaje_desde + dia_referencial - 1, ver
// ReservaController::crearReservaItemDesdeAlternativaItem()); 'manual' =
// un operador la editó a mano (ReservaItemController::update()). Ningún
// recálculo automático futuro (Fase 2, reprogramación) debe tocar un
// ítem 'manual' sin decisión explícita — es la única forma de distinguir
// después "esto se puede recalcular sin miedo" de "esto lo corrigió
// alguien a propósito".
class ReservaItem extends Model
{
    protected $table = 'reserva_items';

    protected $fillable = [
        'reserva_id',
        'alternativa_item_id',
        'fecha',
        'fecha_origen',
        'hora',
        'guia_id',
        'proveedor_tarifa_id',
        'tour_origen_id',
        'salida_operativa_id',
        'tip_afe_igv',
        'destino_tributario',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public const FECHA_ORIGEN_AUTO = 'auto';
    public const FECHA_ORIGEN_MANUAL = 'manual';

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function alternativaItem()
    {
        return $this->belongsTo(AlternativaItem::class, 'alternativa_item_id');
    }

    public function guia()
    {
        return $this->belongsTo(Guia::class, 'guia_id');
    }

    public function proveedorTarifa()
    {
        return $this->belongsTo(ProveedorTarifa::class, 'proveedor_tarifa_id');
    }

    // Sesión 11b4 — mismo propósito que AlternativaItem::tourOrigen(),
    // copiado al crear la reserva.
    public function tourOrigen()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'tour_origen_id');
    }

    // Sesión salida-operativa — a qué salida compartida (tour_origen_id +
    // fecha) quedó enganchado este ítem. Ver SalidaOperativa para el
    // diseño completo; solo origen_tipo=proveedor con modalidad=compartido
    // se engancha automáticamente (ReservaController::engancharSalidaOperativa()),
    // el resto queda disponible para enganche manual desde el tablero.
    public function salidaOperativa()
    {
        return $this->belongsTo(SalidaOperativa::class, 'salida_operativa_id');
    }

    public function pasajeros()
    {
        return $this->belongsToMany(ReservaPasajero::class, 'reserva_item_pasajero', 'reserva_item_id', 'reserva_pasajero_id');
    }

    // Vuelo vendido por la AGENCIA, por pasajero (corrección 2026-08-27 —
    // ver docblock de ReservaItemVueloPasajero) — tabla propia, sin
    // relación con pasajeros()/reserva_item_pasajero de arriba.
    public function vueloPasajeros()
    {
        return $this->hasMany(ReservaItemVueloPasajero::class, 'reserva_item_id');
    }
}
