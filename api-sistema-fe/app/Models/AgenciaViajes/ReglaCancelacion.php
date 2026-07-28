<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Regla de reembolso por franja de días antes del viaje —
// plan-modulo-cotizaciones-reservas.md §4.2. Solo schema + carga inicial en
// esta sesión; la lógica que la consume (calcular
// reserva.porcentaje_reembolso_aplicado al cancelar) es Fase 2. Tenant (sin
// CentralConnection). proveedor_id lleva belongsTo real — null = regla
// general de la agencia.
class ReglaCancelacion extends Model
{
    protected $table = 'reglas_cancelacion';

    protected $fillable = [
        'proveedor_id',
        'dias_min_antes',
        'dias_max_antes',
        'porcentaje_reembolso',
    ];

    protected $casts = [
        'porcentaje_reembolso' => 'decimal:2',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }
}
