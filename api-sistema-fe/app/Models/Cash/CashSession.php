<?php

namespace App\Models\Cash;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4). Estructura únicamente — la
// concurrencia de una sola sesión 'open' por caja la garantiza el índice
// único parcial de la migración (cash_sessions_one_open_per_register), no
// este modelo. Apertura/cierre real es Fase 2.
class CashSession extends Model
{
    use HasFactory;

    protected $table = "cash_sessions";

    protected $fillable = [
        "cash_register_id",
        "opened_by",
        "closed_by",
        "opening_amount",
        "opening_amount_adjusted",
        "opened_at",
        "closed_at",
        "status",
        "expected_cash",
        "counted_cash",
        "difference",
        "difference_reason",
        "closing_notes",
        "shift_label",
    ];

    protected $casts = [
        "opening_amount" => "decimal:2",
        "opening_amount_adjusted" => "boolean",
        "opened_at" => "datetime",
        "closed_at" => "datetime",
        "expected_cash" => "decimal:2",
        "counted_cash" => "decimal:2",
        "difference" => "decimal:2",
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

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, "cash_register_id");
    }

    public function openedByUser()
    {
        return $this->belongsTo(User::class, "opened_by");
    }

    public function closedByUser()
    {
        return $this->belongsTo(User::class, "closed_by");
    }

    public function cashMovements()
    {
        return $this->hasMany(CashMovement::class, "cash_session_id");
    }

    public function cashSessionTotals()
    {
        return $this->hasMany(CashSessionTotal::class, "cash_session_id");
    }

    public function cashSessionDenominations()
    {
        return $this->hasMany(CashSessionDenomination::class, "cash_session_id");
    }
}
