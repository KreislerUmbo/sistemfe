<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Qué tipos de proveedor usa cada agencia — plan-modulo-proveedores.md
// §2.6. Tenant (sin CentralConnection). proveedor_tipo_id no lleva
// belongsTo (cross-boundary a proveedor_tipos central, sin FK real de
// Postgres) — mismo criterio que Proveedor::tipo_id/Servicio::tipo_proveedor_id.
class ProveedorTipoConfig extends Model
{
    protected $table = 'proveedor_tipos_config';

    protected $fillable = [
        'proveedor_tipo_id',
        'habilitado',
    ];

    protected $casts = [
        'habilitado' => 'boolean',
    ];
}
