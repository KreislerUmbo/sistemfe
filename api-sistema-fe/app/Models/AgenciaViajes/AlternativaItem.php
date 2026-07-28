<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Ítem atómico de una alternativa — plan-modulo-cotizaciones-reservas.md
// §3.1/§3.3. Tenant (sin CentralConnection). alternativa_id/proveedor_tarifa_id/
// opcion_mayorista_id llevan belongsTo real (FK dentro de la misma DB
// tenant — opcion_mayorista_id cerrada vía retrofit en Sesión 7b, ver
// 2026_07_28_100300_add_opcion_mayorista_foreign_to_alternativa_items_table.php).
class AlternativaItem extends Model
{
    protected $table = 'alternativa_items';

    protected $fillable = [
        'alternativa_id',
        'proveedor_tarifa_id',
        'opcion_mayorista_id',
        'modo_precio',
        'pax_incluidos',
        'moneda_costo',
        'costo_snapshot',
        'precio_venta_snapshot',
        'descuento_pct',
        'precio_convertido',
    ];

    protected $casts = [
        'pax_incluidos' => 'array',
        'costo_snapshot' => 'decimal:2',
        'precio_venta_snapshot' => 'decimal:2',
        'descuento_pct' => 'decimal:2',
        'precio_convertido' => 'decimal:2',
    ];

    public function alternativa()
    {
        return $this->belongsTo(Alternativa::class, 'alternativa_id');
    }

    public function proveedorTarifa()
    {
        return $this->belongsTo(ProveedorTarifa::class, 'proveedor_tarifa_id');
    }

    public function opcionMayorista()
    {
        return $this->belongsTo(OpcionMayorista::class, 'opcion_mayorista_id');
    }
}
