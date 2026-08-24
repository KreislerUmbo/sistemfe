<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Creada al aceptar una alternativa — plan-modulo-cotizaciones-reservas.md
// §4. Tenant (sin CentralConnection). alternativa_id/mayorista_elegida_id
// llevan belongsTo real (FK dentro de la misma DB tenant).
//
// fecha_cancelacion/motivo_cancelacion/porcentaje_reembolso_aplicado/
// monto_reembolso: columnas listas desde Sesión 8a, pero la lógica de
// cálculo (reglas_cancelacion) es Fase 2 — ver comentario de la migración.
//
// fecha_viaje_desde/fecha_viaje_hasta (Fase 1 del fix Cotización↔Reserva,
// 2026-08-18): copiadas UNA SOLA VEZ desde cotizacion.fecha_viaje_desde/
// hasta al aceptar la alternativa (ReservaController::
// crearReservaDesdeAlternativa()), nunca actualizadas después. REGLA
// FIJA: la fecha de una reserva se lee siempre de acá
// (reserva.fecha_viaje_desde/hasta) — NUNCA de
// reserva.alternativa.cotizacion.fecha_viaje_desde/hasta. Esa cadena
// sigue existiendo y sigue siendo válida (la relación no se quitó, se
// necesita para cliente/destino/precio), pero su fecha_viaje_desde/hasta
// refleja la PROPUESTA comercial vigente hoy, editable libremente sin
// ningún guard (a propósito, ver CotizacionController::update()) — no el
// compromiso operativo ya congelado de esta reserva. Ver
// docs/planning/agencia-de-viajes/plan-modulo-cotizaciones-reservas.md
// y el brief "Fix fechas Cotización↔Reserva, FASE 1".
//
// fecha_viaje_desde_original/hasta_original/fecha_reprogramacion/
// motivo_reprogramacion (Fase 2 del fix, 2026-08-19): auditoría SIMPLE de
// la reprogramación más reciente (ReservaController::reprogramar()) — no
// un historial completo (mismo trade-off ya aceptado por
// fecha_cancelacion/motivo_cancelacion). Reprogramar más de una vez pisa
// estos 4 campos con el estado anterior a la última reprogramación, no el
// original de creación.
//
// facturacion_externa/referencia_externa/fecha_facturacion_externa
// (PEGAR-EN-CLAUDE-CODE-facturacion-externa-tenant.md, 2026-08-20): override
// por reserva, independiente de tenants.facturacion_habilitada (central) —
// el vendedor puede marcar/desmarcar libremente mientras la reserva no tenga
// ninguna fila en reserva_ventas (ver ReservaController::
// actualizarFacturacionExterna()). Anotación pura, sin validar nada contra
// ningún sistema externo, sin historial (se limpia al desmarcar).
class Reserva extends Model
{
    protected $table = 'reserva';

    protected $fillable = [
        'alternativa_id',
        'mayorista_elegida_id',
        'estado_reserva_mayorista',
        'estado',
        'fecha_viaje_desde',
        'fecha_viaje_hasta',
        'fecha_viaje_desde_original',
        'fecha_viaje_hasta_original',
        'fecha_reprogramacion',
        'motivo_reprogramacion',
        'fecha_cancelacion',
        'motivo_cancelacion',
        'porcentaje_reembolso_aplicado',
        'monto_reembolso',
        'facturacion_externa',
        'referencia_externa',
        'fecha_facturacion_externa',
    ];

    protected $casts = [
        'fecha_viaje_desde' => 'date',
        'fecha_viaje_hasta' => 'date',
        'fecha_viaje_desde_original' => 'date',
        'fecha_viaje_hasta_original' => 'date',
        'fecha_reprogramacion' => 'datetime',
        'fecha_cancelacion' => 'datetime',
        'porcentaje_reembolso_aplicado' => 'decimal:2',
        'monto_reembolso' => 'decimal:2',
        'facturacion_externa' => 'boolean',
        'fecha_facturacion_externa' => 'date',
    ];

    public function alternativa()
    {
        return $this->belongsTo(Alternativa::class, 'alternativa_id');
    }

    public function mayoristaElegida()
    {
        return $this->belongsTo(OpcionMayorista::class, 'mayorista_elegida_id');
    }

    public function pasajeros()
    {
        return $this->hasMany(ReservaPasajero::class, 'reserva_id');
    }

    public function items()
    {
        return $this->hasMany(ReservaItem::class, 'reserva_id');
    }

    public function ventas()
    {
        return $this->hasMany(ReservaVenta::class, 'reserva_id');
    }

    // Adelantos (Advance, core) etiquetados contra esta reserva — tabla
    // reserva_anticipos, existía desde Sesión 8b sin ningún controller que
    // la usara (hallazgo de auditoría del módulo Adelantos, 2026-08-21).
    public function anticipos()
    {
        return $this->hasMany(ReservaAnticipo::class, 'reserva_id');
    }
}
