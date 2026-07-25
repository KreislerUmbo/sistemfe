<?php

namespace App\Models\Credit;

use App\Models\Sale\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §2.4.
// Pivot: a qué venta/cuota se aplicó cada parte de un payment_receipt.
// La tabla más sensible del módulo para el cálculo de saldo — sin
// SoftDeletes a propósito (ver migración create_payment_applications_table.php):
// 'anulada'/'trasladada' son los únicos caminos de baja, nunca DELETE.
class PaymentApplication extends Model
{
    use HasFactory;

    protected $table = "payment_applications";

    protected $fillable = [
        "payment_receipt_id",
        "sale_id",
        "installment_id",      // nullable, solo si la venta es cuotas_fijas
        "monto_aplicado",
        "monto_mora_cobrado",
        "orden_aplicacion",
        "estado",               // activo | anulada | trasladada
        "refund_id",            // solo si estado='anulada' por liquidación (§3.12)
        "origen_application_id", // solo si estado='trasladada' (§3.13)
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

    public function paymentReceipt()
    {
        return $this->belongsTo(PaymentReceipt::class, "payment_receipt_id");
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, "sale_id");
    }

    public function installment()
    {
        return $this->belongsTo(Installment::class, "installment_id");
    }

    public function refund()
    {
        return $this->belongsTo(PaymentRefund::class, "refund_id");
    }

    // Fila de origen del traspaso (§3.13) — solo tiene valor si estado='trasladada'.
    public function origenApplication()
    {
        return $this->belongsTo(PaymentApplication::class, "origen_application_id");
    }
}
