<?php

namespace App\Models\Cash;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Sin SoftDeletes: is_active ya
// cumple ese rol.
//
// 14-oct-2026 (diseño, sin implementar): se reemplazará por la tabla única
// `proveedores` del sistema base (plan-tours-venta-simple-costo-real.md §3.7c).
// No ampliar este catálogo.
class Supplier extends Model
{
    use HasFactory;

    protected $table = "suppliers";

    protected $fillable = [
        "name",
        "document",
        "phone",
        "is_active",
    ];

    protected $casts = [
        "is_active" => "boolean",
    ];
}
