<?php

namespace App\Models\Cash;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4). Desnormalizado, sin lógica
// de negocio en esta fase — nada lo llena todavía (Fase 2+).
class CashSessionTotal extends Model
{
    use HasFactory;

    protected $table = "cash_session_totals";

    protected $fillable = [
        "cash_session_id",
        "payment_method_id",
        "expected_amount",
        "movement_count",
    ];

    protected $casts = [
        "expected_amount" => "decimal:2",
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

    public function cashSession()
    {
        return $this->belongsTo(CashSession::class, "cash_session_id");
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, "payment_method_id");
    }
}
