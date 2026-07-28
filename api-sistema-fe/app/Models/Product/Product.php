<?php

namespace App\Models\Product;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        "title",
        "sku",
        "categorie_id",
        "imagen",
        "price_general",
        "price_company",
        "description",
        "is_discount",
        "max_discount",
        "disponiblidad",
        "state",
        "unidad_medida",
        "stock",
        "include_igv",
        "is_icbper", //impuesto a la bolsa de plastico 1=no 2=si
        "is_ivap", //impuesto al arroz pilado    1=no 2=si
        "is_isc",
        "percentage_isc", //porcentaje de impuesto de consumo cuando el tipo_isc es 1 o 3
        "tipo_isc",
        "monto_isc_fijo",
        "contenido_neto_litros", //volumen en litros de la presentación — solo cuando tipo_isc='02' se aplica por litro (ej. cerveza)
        "is_especial_nota",
        // false = producto sin inventario real (ej. servicios de viaje) — SaleController
        // todavía decrementa/incrementa stock sin condición, ver 2026_07_28_130000_alter_products_add_controla_stock.php
        "controla_stock",
        // ── Campos SUNAT — naturaleza tributaria del producto ───────────
        "tipo_bien_servicio",
        "codigo_detraccion",
        "aplica_percepcion",
        "tip_afe_igv_default",
    ];

    protected $casts = [
        'price_general'  => 'decimal:6',
        'price_company'  => 'decimal:6',
        'percentage_isc' => 'decimal:4',
        'monto_isc_fijo' => 'decimal:4',
        'contenido_neto_litros' => 'decimal:4',
        'is_isc'            => 'boolean',
        'aplica_percepcion' => 'boolean',
        'controla_stock'    => 'boolean',
    ];


    public function setCreatedAtAttribute($value)
    {
        date_default_timezone_set('America/Lima');
        $this->attributes["created_at"] = Carbon::now();
    }

    public function setUpdatedAtAttribute($value)
    {
        date_default_timezone_set("America/Lima");
        $this->attributes["updated_at"] = Carbon::now();
    }


    public function categorie()
    {
        return $this->belongsTo(Categorie::class, "categorie_id");
    }

    // Relación
    public function detraction()
    {
        return $this->belongsTo(DetractionCode::class, 'codigo_detraccion', 'codigo');
    }

    public function scopeFilterMultiple($query, $search, $categorie_id, $state, $unidad_medida)
    {
    // trim para eliminar espacios en blanco
        $search = trim($search ?? '');
        $categorie_id = trim($categorie_id ?? '');
        $state = trim($state ?? '');
        $unidad_medida = trim($unidad_medida ?? '');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ILIKE', '%' . $search . '%')  // ahora con % al inicio
                    ->orWhere('sku', 'ILIKE', '%' . $search . '%');
            });
        }

        if ($categorie_id !== '') {
            $query->where('categorie_id', $categorie_id);
        }
        if ($state !== '') {
            $query->where('state', $state);
        }
        if ($unidad_medida !== '') {
            $query->where('unidad_medida', $unidad_medida);
        }

        return $query;
    }
}
