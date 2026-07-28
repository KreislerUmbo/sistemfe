<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Pagos YA REALIZADOS a proveedores/mayoristas — plan-modulo-cotizaciones-reservas.md
// §6 (intro). Contraparte de CronogramaPagoProveedor (lo PROGRAMADO). Tenant
// (sin CentralConnection). proveedor_id/opcion_mayorista_id llevan belongsTo
// real (ambos nullable — regla "uno de los dos, no ambos" se valida en
// aplicación, no en schema, mismo criterio que CronogramaPagoProveedor).
class PagoProveedor extends Model
{
    protected $table = 'pago_proveedor';

    protected $fillable = [
        'proveedor_id',
        'opcion_mayorista_id',
        'monto',
        'moneda',
        'fecha',
        'tipo',
        'referencia_documento',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
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
