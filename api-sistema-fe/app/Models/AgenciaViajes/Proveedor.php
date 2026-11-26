<?php

namespace App\Models\AgenciaViajes;

use App\Models\AgenciaViajes\Concerns\FotosConTipo;
use App\Models\Proveedor as ProveedorBase;
use Illuminate\Support\Facades\DB;

// plan-modulo-proveedores.md §2.6 — schema validado por el negocio.
// Tenant (sin CentralConnection). tipo_id no lleva belongsTo (cross-boundary
// a proveedor_tipos central, sin FK real de Postgres) — mismo criterio que
// Servicio::tipo_proveedor_id.
//
// T-133: la tabla y los datos básicos son del sistema base (App\Models\Proveedor); acá queda
// lo turístico.
class Proveedor extends ProveedorBase
{
    use FotosConTipo;

    protected $fillable = [
        'codigo',
        'razon_social',
        'nombre_comercial',
        'descripcion',
        'tipo_persona',
        'tipo_documento',
        'numero_documento',
        'direccion',
        'direccion_fiscal',
        'ciudad',
        'es_no_domiciliado',
        'pais',
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
        'es_no_domiciliado' => 'boolean',
        'fotos' => 'array',
    ];

    // T-020 — las fotos sueltas (forma vieja) quedan como 'otra'; la fachada se marca a mano.
    protected static function tipoFotoPorDefecto(): string
    {
        return 'otra';
    }

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
