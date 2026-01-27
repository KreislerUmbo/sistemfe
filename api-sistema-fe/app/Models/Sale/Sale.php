<?php

namespace App\Models\Sale;

use App\Models\Client\Client;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use SoftDeletes;
    use HasFactory;
    protected $table = "sales";

    protected $fillable = [
        "serie",
        "correlativo",
        "n_operacion",
        "user_id",
        "client_id",
        "type_client",
        "currency",
        "subtotal",
        "total",
        "is_exportacion",
        "discount",
        "discount_global",
        "n_comprobante_anticipo",
        "amount_anticipo",
        "igv",
        "igv_discount_general",
        "type_payment",
        "state_sale",
        "state_payment",
        "debt",
        "paid_out",
        "retencion_igv",
        "description",
        "cdr",
        "xml",
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


    public function client() //esta funcion es para la relacion con clients
    {
        return $this->belongsTo(Client::class, "client_id");
    }

    public function user() //esta funcion es para la relacion con users
    {
        return $this->belongsTo(User::class, "user_id"); //belongsTo es una relacion de uno a muchos y de muchos a uno es 
    }

    public function sale_payments()
    {
        return $this->hasMany(SalePayment::class, "sale_id");
    }
    public function sale_details()
    {
        return $this->hasMany(SaleDetail::class, "sale_id");
    }
}
