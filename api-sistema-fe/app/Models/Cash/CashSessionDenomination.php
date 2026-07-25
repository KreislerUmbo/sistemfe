<?php

namespace App\Models\Cash;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4). Detalle físico opcional del
// arqueo, sin lógica de negocio en esta fase.
class CashSessionDenomination extends Model
{
    use HasFactory;

    protected $table = "cash_session_denominations";

    protected $fillable = [
        "cash_session_id",
        "denomination",
        "quantity",
        "subtotal",
    ];

    protected $casts = [
        "denomination" => "decimal:2",
        "subtotal" => "decimal:2",
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
}
