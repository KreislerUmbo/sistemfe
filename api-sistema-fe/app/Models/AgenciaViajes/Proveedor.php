<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        'descripcion',
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
        'fotos',
        'observaciones',
        'estado',
        'tipo_id',
        'margen_default_tipo',
        'margen_default_valor',
        'es_referencial',
    ];

    protected $casts = [
        'estado' => 'boolean',
        'margen_default_valor' => 'decimal:2',
        'es_referencial' => 'boolean',
        'fotos' => 'array',
    ];

    public function proveedorServicios()
    {
        return $this->hasMany(ProveedorServicio::class, 'proveedor_id');
    }

    public function alojamientoDetalle()
    {
        return $this->hasOne(ProveedorAlojamientoDetalle::class, 'proveedor_id');
    }

    // amenidad_id referencia amenidades (central) — sin FK real cross-DB.
    // NO es un belongsToMany real a propósito: Eloquent arma el JOIN contra
    // la tabla pivote usando la conexión del modelo RELACIONADO (Amenidad,
    // central), pero proveedor_amenidad vive en la BD del tenant — el JOIN
    // buscaría la tabla en la base equivocada. Resuelto en dos pasos
    // (ids del pivote, tenant → Amenidad::whereIn, central), mismo criterio
    // que ProveedorController::tarifasHotel() ya usa para ProveedorTipo.
    public function amenidades()
    {
        $amenidadIds = DB::table('proveedor_amenidad')->where('proveedor_id', $this->id)->pluck('amenidad_id');

        return Amenidad::whereIn('id', $amenidadIds)->get();
    }
}
