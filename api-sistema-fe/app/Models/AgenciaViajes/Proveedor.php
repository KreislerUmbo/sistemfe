<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// plan-modulo-proveedores.md §2.6 — schema validado por el negocio.
// Tenant (sin CentralConnection). tipo_id no lleva belongsTo (cross-boundary
// a proveedor_tipos central, sin FK real de Postgres) — mismo criterio que
// Servicio::tipo_proveedor_id.
class Proveedor extends Model
{
    protected $table = 'proveedores';

    protected $fillable = [
        'codigo',
        'razon_social',
        'nombre_comercial',
        'tipo_persona',
        'tipo_documento',
        'numero_documento',
        'direccion',
        'pais_id',
        'departamento_id',
        'provincia_id',
        'distrito_id',
        'telefono',
        'celular',
        'whatsapp',
        'email',
        'pagina_web',
        'facebook',
        'instagram',
        'tiktok',
        'linkedin',
        'logo',
        'observaciones',
        'estado',
        'tipo_id',
        'margen_default_tipo',
        'margen_default_valor',
    ];

    protected $casts = [
        'estado' => 'boolean',
        'margen_default_valor' => 'decimal:2',
    ];

    public function proveedorServicios()
    {
        return $this->hasMany(ProveedorServicio::class, 'proveedor_id');
    }
}
