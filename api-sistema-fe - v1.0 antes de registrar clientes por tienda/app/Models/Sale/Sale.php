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

public function getFirsPaymentAttribute(){
    return $this->sale_payments()->first() ? $this->sale_payments()->first(): null;}

    public function scopefilterMultiple($query, $search_product, $categorie_id, $search, $search_client, $state_sale, $type_payment, $start_date, $end_date)
    {
        if ($search_product) {
            $query->whereHas("sale_details", function ($q) use ($search_product) {
                $q->whereHas("product", function ($subq) use ($search_product) {
                    $subq->where("title", "ILIKE", $search_product . "%");
                }); //whereHas es una relacion de muchos a muchos", $search_product;
            });
        }
        if ($categorie_id) {
            $query->whereHas("sale_details", function ($q) use ($categorie_id) {
                $q->where("product_categorie_id", $categorie_id);
            });
        }
        if ($search) { //busqueda por id de la venta
            $query->where("id", "ILIKE", $search . "%");
        }
        if ($search_client) {
            $query->whereHas("client", function ($q) use ($search_client) {
                $q->whereRaw("(COALESCE(clients.phone,'') || ' ' || COALESCE(clients.full_name,'') || ' ' || COALESCE(clients.n_document,'')) ILIKE ?", ["%{$search_client}%"]);
            });
        }
        if ($state_sale) {
            $query->where("state_sale", $state_sale);
        }
        if ($type_payment) {
            $query->where("type_payment", $type_payment);
        }
        if ($start_date && $end_date) {
            $query->whereBetween("created_at", [
              Carbon::parse($start_date)->format("Y-m-d")." 00:00:00",
              Carbon::parse($end_date)->format("Y-m-d")." 23:59:59", 
                ]);
        }
    

        return $query;
    }
}
