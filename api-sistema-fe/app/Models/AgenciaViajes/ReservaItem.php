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
        'opcion_mayorista_id',
        'opcion_mayorista_original_id',
        'motivo_reasignacion_mayorista',
        'fecha_reasignacion_mayorista',
        'veces_reasignado_mayorista',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_reasignacion_mayorista' => 'datetime',
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

    // Sesión 12h — quién opera realmente el destino internacional de este
    // ítem. Se copia de alternativaItem()->opcion_mayorista_id al aceptar
    // la reserva, y se reescribe SOLO vía
    // ReservaController::reasignarMayorista() después (nunca por update()
    // genérico del modelo).
    public function opcionMayorista()
    {
        return $this->belongsTo(OpcionMayorista::class, 'opcion_mayorista_id');
    }

    // El mayorista con el que se aceptó la reserva originalmente — se
    // escribe una única vez, en la PRIMERA reasignación (nunca se pisa en
    // reasignaciones siguientes), mismo trade-off de auditoría simple que
    // Reserva::fecha_viaje_desde_original.
    public function opcionMayoristaOriginal()
    {
        return $this->belongsTo(OpcionMayorista::class, 'opcion_mayorista_original_id');
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
