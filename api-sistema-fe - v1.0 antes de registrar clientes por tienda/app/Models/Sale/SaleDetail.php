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
        "tip_afe_igv",
        "per_icbper",
        "icbper",
        "percentage_isc",
        "isc",
        "unidad_medida",
        "quantity",
        "price_base",
        "price_final",
        "discount",
        "subtotal",
        "igv",
        "description",
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
