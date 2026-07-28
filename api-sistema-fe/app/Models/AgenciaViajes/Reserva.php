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
class Reserva extends Model
{
    protected $table = 'reserva';

    protected $fillable = [
        'alternativa_id',
        'mayorista_elegida_id',
        'estado_reserva_mayorista',
        'estado',
        'fecha_cancelacion',
        'motivo_cancelacion',
        'porcentaje_reembolso_aplicado',
        'monto_reembolso',
    ];

    protected $casts = [
        'fecha_cancelacion' => 'datetime',
        'porcentaje_reembolso_aplicado' => 'decimal:2',
        'monto_reembolso' => 'decimal:2',
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
}
