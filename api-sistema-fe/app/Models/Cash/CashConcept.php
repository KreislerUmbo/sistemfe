<?php

namespace App\Models\Cash;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Sin SoftDeletes: is_active ya
// cumple ese rol. 'direction' validado a nivel de aplicación ('in'|'out'), ver
// comentario en la migración create_cash_concepts_table.php.
class CashConcept extends Model
{
    use HasFactory;

    protected $table = "cash_concepts";

    protected $fillable = [
        "name",
        "direction",
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
}
