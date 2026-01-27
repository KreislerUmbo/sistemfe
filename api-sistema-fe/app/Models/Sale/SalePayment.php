<?php

namespace App\Models\Sale;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalePayment extends Model
{
    use SoftDeletes;
    protected $fillable = [
        "sale_id",
        "method_payment",
        "amount",
        "date_payment",
        "comments", //COLLATE en postgresql significa que se ignora la mayuscula en la busqueda
        "user_id",
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
}
