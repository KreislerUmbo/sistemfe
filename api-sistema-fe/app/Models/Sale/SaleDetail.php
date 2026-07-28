<?php

namespace App\Models\Sale;

use App\Models\Product\Categorie;
use App\Models\Product\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleDetail extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $table = "sale_details";
    protected $fillable = [
        "sale_id",
        "product_id",
        "product_categorie_id",

        // Tipo de afectación IGV (Catálogo 07 SUNAT)
        // Siempre como string: '10', '17', '20', '30', '40'
        "tip_afe_igv",

        // ICBPER (bolsa plástica)
        "per_icbper",
        "icbper",

        // ISC (Impuesto Selectivo al Consumo)
        "percentage_isc",
        "isc",
        
        // Régimen ISC: '01'=Al valor, '02'=Específico, '03'=Al valor/PVP
        "tipo_isc",
        'monto_isc_fijo',

        "unidad_medida",
         // Cantidades y precios

        "quantity",
        "price_base",
        "price_final",
        "discount",

         // Totales de la línea
        "subtotal",
        "igv",
        
        "mto_valor_venta",
        "mto_base_igv",
        "porcentaje_igv",
        "total_impuestos",
        // Campos requeridos por Greenter

        "description",

        // Detalle real concatenado de qué incluye la línea (ej. "Hotel Río
        // Cumbaza (2 noches) + Tour a Lamas") — vertical Agencia de Viajes,
        // Sesión 9a. Distinto de "description" (arriba), que existe desde
        // el schema original pero está muerta en la práctica.
        "descripcion_detalle",
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


    public function sale()
    {
        return $this->belongsTo(Sale::class, "sale_id");
    }
    public function product()
    {
        return $this->belongsTo(Product::class, "product_id");
    }
    public function product_categorie()
    {
        return $this->belongsTo(Categorie::class, "product_categorie_id");
    }
}
