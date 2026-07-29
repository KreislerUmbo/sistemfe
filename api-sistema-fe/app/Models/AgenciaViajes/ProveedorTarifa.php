<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// plan-modulo-proveedores.md §2.6 + plan-modulo-cotizaciones-reservas.md
// §2.2/§2.3. Tenant (sin CentralConnection). proveedor_servicio_id lleva
// belongsTo real (FK dentro de la misma DB tenant, Sesión 4).
// temporada_id NO lleva relación — cross-boundary a temporadas (central,
// Sesión 1), sin FK real de Postgres, mismo criterio que
// Proveedor::tipo_id/Servicio::tipo_proveedor_id.
//
// tipo_habitacion: RETROFIT Sesión 11a (ver migración
// 2026_07_28_170000_add_tipo_habitacion_to_proveedor_tarifas_table.php) —
// promovida de diferenciador (JSON) a columna real, mismo enum que ya usa
// OpcionHotelTarifa desde Sesión 5.
class ProveedorTarifa extends Model
{
    protected $table = 'proveedor_tarifas';

    protected $fillable = [
        'proveedor_servicio_id',
        'tipo_tarifa',
        'modalidad',
        'moneda',
        'diferenciador',
        'tipo_habitacion',
        'precio_costo',
        'margen_tipo',
        'margen_valor',
        'descuento_maximo_pct',
        'margen_minimo_pct',
        'precio_venta_adulto',
        'precio_venta_nino',
        'precio_venta_infante',
        'edad_min_nino',
        'edad_max_nino',
        'edad_max_infante',
        'temporada_id',
        'vigente_desde',
        'vigente_hasta',
        'tip_afe_igv',
        'destino_tributario',
    ];

    protected $casts = [
        'diferenciador' => 'array',
        'precio_costo' => 'decimal:2',
        'margen_valor' => 'decimal:2',
        'descuento_maximo_pct' => 'decimal:2',
        'margen_minimo_pct' => 'decimal:2',
        'precio_venta_adulto' => 'decimal:2',
        'precio_venta_nino' => 'decimal:2',
        'precio_venta_infante' => 'decimal:2',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    public function proveedorServicio()
    {
        return $this->belongsTo(ProveedorServicio::class, 'proveedor_servicio_id');
    }

    public function paqueteItems()
    {
        return $this->hasMany(PaquetePlantillaItem::class, 'proveedor_tarifa_id');
    }
}
