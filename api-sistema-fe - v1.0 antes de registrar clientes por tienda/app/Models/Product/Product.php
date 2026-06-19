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
        "is_icbper",//impuesto a la bolsa de plastico 1=no 2=si
        "is_ivap", //impuesto al arroz pilado    1=no 2=si
        "percentage_isc",//porcentaje de impuesto de consumo
        "is_especial_nota",
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
        return $this->belongsTo(Categorie::class,"categorie_id");
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
            $q->where('title', 'ILIKE', $search . '%')
              ->orWhere('sku', 'ILIKE', $search . '%');
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
