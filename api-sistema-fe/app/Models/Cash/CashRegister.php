<?php

namespace App\Models\Cash;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4). Sin SoftDeletes: is_active
// ya cumple ese rol. Sin lógica de negocio (apertura/cierre es Fase 2).
class CashRegister extends Model
{
    use HasFactory;

    protected $table = "cash_registers";

    protected $fillable = [
        "branch_id",
        "name",
        "code",
        "type",
        "is_active",
        "blind_close",
        "default_opening_amount",
    ];

    protected $casts = [
        "is_active" => "boolean",
        "blind_close" => "boolean",
        "default_opening_amount" => "decimal:2",
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

    public function branch()
    {
        return $this->belongsTo(Branch::class, "branch_id");
    }

    public function cashSessions()
    {
        return $this->hasMany(CashSession::class, "cash_register_id");
    }
}
