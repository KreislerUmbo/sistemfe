<?php

namespace App\Models\Cash;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4). Sin SoftDeletes: is_active
// ya cumple ese rol, mismo criterio que Fase 0.
class Branch extends Model
{
    use HasFactory;

    protected $table = "branches";

    protected $fillable = [
        "name",
        "code",
        "address",
        "is_active",
    ];

    protected $casts = [
        "is_active" => "boolean",
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

    public function cashRegisters()
    {
        return $this->hasMany(CashRegister::class, "branch_id");
    }
}
