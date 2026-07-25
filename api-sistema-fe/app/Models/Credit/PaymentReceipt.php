<?php

namespace App\Models\Credit;

use App\Models\Client\Client;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §2.3.
// Recibo de cobro interno (no regulado por SUNAT), unifica el caso general
// (multi-venta) y el específico (§3.2/§3.3). Sin SoftDeletes a propósito:
// anulación es siempre estado = 'anulado' con motivo/auditoría, nunca DELETE.
class PaymentReceipt extends Model
{
    use HasFactory;

    protected $table = "payment_receipts";

    protected $fillable = [
        "numero_recibo",
        "client_id",
        "fecha_pago",
        "medio_pago",
        "nro_operacion",
        "monto_total",
        "monto_no_aplicado",
        "registrado_por",
        "estado",              // activo | anulado
        "motivo_anulacion",
        "anulado_por",
        "anulado_en",
    ];

    protected $casts = [
        "fecha_pago" => "date",
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

    public function client()
    {
        return $this->belongsTo(Client::class, "client_id");
    }

    public function applications()
    {
        return $this->hasMany(PaymentApplication::class, "payment_receipt_id");
    }

    public function registradoPor()
    {
        return $this->belongsTo(\App\Models\User::class, "registrado_por");
    }

    public function anuladoPor()
    {
        return $this->belongsTo(\App\Models\User::class, "anulado_por");
    }

    // Centraliza el cálculo para el PDF del recibo (§3.10) — mismo criterio
    // que Sale::resumenImpresion(), evita duplicar la agregación entre el
    // controlador y las 2 vistas Blade (A4/ticket80mm).
    public function resumenImpresion(): array
    {
        $aplicaciones = $this->applications;

        return [
            "total_capital_aplicado" => round($aplicaciones->sum("monto_aplicado"), 2),
            "total_mora_cobrada" => round($aplicaciones->sum("monto_mora_cobrado"), 2),
            "cantidad_ventas_afectadas" => $aplicaciones->pluck("sale_id")->unique()->count(),
            "ventas_afectadas" => $aplicaciones->groupBy("sale_id")->map(function ($filas) {
                $venta = $filas->first()->sale;

                return [
                    "sale_id" => $venta->id,
                    "n_operacion" => $venta->n_operacion,
                    "monto_capital_recibo" => round($filas->sum("monto_aplicado"), 2),
                    "monto_mora_recibo" => round($filas->sum("monto_mora_cobrado"), 2),
                    "saldo_pendiente_actual" => (float) $venta->saldo_pendiente,
                ];
            })->values(),
        ];
    }
}
