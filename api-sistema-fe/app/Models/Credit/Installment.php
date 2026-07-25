<?php

namespace App\Models\Credit;

use App\Models\Sale\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §2.2.
// Solo existen filas si sale.credit_type = 'cuotas_fijas'. Sin SoftDeletes
// a propósito (ver migración create_installments_table.php): anulación es
// siempre estado = 'anulada' con motivo/auditoría, nunca un DELETE.
class Installment extends Model
{
    use HasFactory;

    protected $table = "installments";

    protected $fillable = [
        "sale_id",
        "numero_cuota",
        "monto_programado",
        "fecha_vencimiento",
        "estado",             // pendiente | pagada | parcial | vencida | anulada
        "motivo_anulacion",
        "anulado_por",
        "anulado_en",
    ];

    protected $casts = [
        "fecha_vencimiento" => "date",
        "anulado_en" => "datetime",
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

    // Módulo Amortizaciones — plan-modulo-amortizaciones.md §6.4 (Fase 4).
    // Necesaria para calcular el saldo pendiente de la cuota: no hay
    // columna cacheada de "monto pagado" a nivel de installment, se deriva
    // sumando las aplicaciones activas (ver CreditPaymentAllocator).
    public function paymentApplications()
    {
        return $this->hasMany(PaymentApplication::class, "installment_id");
    }
}
