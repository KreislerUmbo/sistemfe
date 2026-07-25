<?php

namespace App\Models\Cash;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Sin SoftDeletes: is_active ya
// cumple ese rol (desactivar no borra ni afecta ventas históricas).
class PaymentMethod extends Model
{
    use HasFactory;

    protected $table = "payment_methods";

    protected $fillable = [
        "code",
        "name",
        "is_active",
        "sort_order",
        "affects_cash_count",
    ];

    protected $casts = [
        "is_active" => "boolean",
        "affects_cash_count" => "boolean",
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
}
