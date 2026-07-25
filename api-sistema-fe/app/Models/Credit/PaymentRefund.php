<?php

namespace App\Models\Credit;

use App\Models\Sale\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §3.12.
// Devolución de pagos por anulación con mercadería devuelta y retención
// parcial. Sin SoftDeletes a propósito, misma razón que el resto del módulo.
class PaymentRefund extends Model
{
    use HasFactory;

    protected $table = "payment_refunds";

    protected $fillable = [
        "sale_id",
        "monto_pagado_total",
        "monto_retenido",
        "motivo_retencion",
        "monto_devuelto",
        "medio_devolucion",
        "nro_operacion_devolucion",
        "fecha_devolucion",
        "autorizado_por",
        "estado",           // pendiente | completado
    ];

    protected $casts = [
        "fecha_devolucion" => "date",
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

    public function sale()
    {
        return $this->belongsTo(Sale::class, "sale_id");
    }

    public function applications()
    {
        return $this->hasMany(PaymentApplication::class, "refund_id");
    }
}
