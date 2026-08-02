<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Ítem atómico de una alternativa — plan-modulo-cotizaciones-reservas.md
// §3.1/§3.3. Tenant (sin CentralConnection). alternativa_id/proveedor_tarifa_id/
// opcion_mayorista_id llevan belongsTo real (FK dentro de la misma DB
// tenant — opcion_mayorista_id cerrada vía retrofit en Sesión 7b, ver
// 2026_07_28_100300_add_opcion_mayorista_foreign_to_alternativa_items_table.php).
//
// origen_tipo/cantidad/descripcion_manual: RETROFIT Sesión 11b (ver
// 2026_07_28_180000_add_origen_tipo_cantidad_descripcion_manual_to_alternativa_items_table.php).
class AlternativaItem extends Model
{
    protected $table = 'alternativa_items';

    public const ORIGEN_PROVEEDOR = 'proveedor';
    public const ORIGEN_MAYORISTA = 'mayorista';
    public const ORIGEN_PASAJE_AEREO = 'pasaje_aereo';
    public const ORIGEN_MANUAL = 'manual';

    public const ORIGENES = [
        self::ORIGEN_PROVEEDOR,
        self::ORIGEN_MAYORISTA,
        self::ORIGEN_PASAJE_AEREO,
        self::ORIGEN_MANUAL,
    ];

    protected $fillable = [
        'alternativa_id',
        'origen_tipo',
        'proveedor_tarifa_id',
        'opcion_mayorista_id',
        'tour_origen_id',
        'dia_referencial',
        'descripcion_manual',
        'modo_precio',
        'cantidad',
        'pax_incluidos',
        'moneda_costo',
        'costo_snapshot',
        'precio_venta_snapshot',
        'descuento_pct',
        'precio_convertido',
    ];

    protected $casts = [
        'pax_incluidos' => 'array',
        'cantidad' => 'integer',
        'dia_referencial' => 'integer',
        'costo_snapshot' => 'decimal:2',
        'precio_venta_snapshot' => 'decimal:2',
        'descuento_pct' => 'decimal:2',
        'precio_convertido' => 'decimal:2',
    ];

    protected $appends = ['total', 'total_convertido'];

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

    // Sesión 11b4 — tour_simple de origen cuando este ítem vino de explotar
    // un paquete_combo (ComboExplosionService). Null si el ítem es manual o
    // no vino de un combo.
    public function tourOrigen()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'tour_origen_id');
    }

    public function cotizacionPasajeAereo()
    {
        return $this->hasOne(CotizacionPasajeAereo::class, 'alternativa_item_id');
    }

    // Total del ítem (sección 3 del plan): 'manual' nunca multiplica por
    // cantidad (no es relevante para ese origen). Para el resto, solo
    // modo_precio='tarifa_fija' multiplica — 'por_persona' ya viene
    // resuelto en precio_venta_snapshot (repartido entre pax_incluidos al
    // crearse), multiplicar de nuevo sería duplicar el cálculo.
    public function getTotalAttribute(): float
    {
        $precio = (float) $this->precio_venta_snapshot;

        if ($this->origen_tipo === self::ORIGEN_MANUAL) {
            return $precio;
        }

        if ($this->modo_precio === 'tarifa_fija') {
            return $precio * $this->cantidad;
        }

        return $precio;
    }

    // Igual que total(), pero sobre precio_convertido (post-descuento, en
    // moneda_cotizacion de la alternativa) — es lo que realmente se suma
    // para el panel de precio en vivo (§7.1). precio_convertido es unitario,
    // igual que precio_venta_snapshot (ver comentario de la migración de
    // 'cantidad'), así que se multiplica con la misma regla.
    public function getTotalConvertidoAttribute(): float
    {
        $precio = (float) ($this->precio_convertido ?? $this->precio_venta_snapshot);

        if ($this->origen_tipo === self::ORIGEN_MANUAL) {
            return $precio;
        }

        if ($this->modo_precio === 'tarifa_fija') {
            return $precio * $this->cantidad;
        }

        return $precio;
    }
}
