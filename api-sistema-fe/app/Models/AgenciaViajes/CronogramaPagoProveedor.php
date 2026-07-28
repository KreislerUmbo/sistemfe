<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Lo que la agencia DEBE pagar y cuándo — plan-modulo-cotizaciones-reservas.md
// §4.6. Tenant (sin CentralConnection). proveedor_id/opcion_mayorista_id
// llevan belongsTo real (ambos nullable — regla "uno de los dos, no ambos"
// se valida en aplicación, no en schema).
class CronogramaPagoProveedor extends Model
{
    protected $table = 'cronograma_pago_proveedor';

    protected $fillable = [
        'proveedor_id',
        'opcion_mayorista_id',
        'numero_cuota',
        'monto_programado',
        'fecha_vencimiento',
        'estado',
    ];

    protected $casts = [
        'monto_programado' => 'decimal:2',
        'fecha_vencimiento' => 'date',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function opcionMayorista()
    {
        return $this->belongsTo(OpcionMayorista::class, 'opcion_mayorista_id');
    }
}
