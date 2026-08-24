<?php

namespace App\Models\Advance;

use App\Models\Client\Client;
use App\Models\Sale\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Advance extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $table = "advances";

    protected $fillable = [
        "client_id",
        "sale_id",         // venta type='advance' — comprobante SUNAT del adelanto
        "amount",
        "applied_amount",  // cacheado, actualizado atómicamente junto con advance_applications
        "refunded_amount", // cacheado, actualizado atómicamente junto con advance_refunds
        "currency",
        "status",          // pending | partially_applied | applied | partially_refunded | refunded
        "payment_method",
        "notes",
        // Tier 2 — auditoría simple de la corrección más reciente (ver
        // AdvanceController::corregir()). Nunca editados a mano fuera de ahí.
        "corrected_from_sale_id",
        "correction_reason",
        "corrected_at",
        "corrected_by",
    ];

    // ── Timestamps en zona Lima (mismo patrón que Sale/Note) ────────────
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

    // ── Relaciones ───────────────────────────────────────────────────────
    public function client()
    {
        return $this->belongsTo(Client::class, "client_id");
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, "sale_id");
    }

    public function applications()
    {
        return $this->hasMany(AdvanceApplication::class, "advance_id");
    }

    public function refunds()
    {
        return $this->hasMany(AdvanceRefund::class, "advance_id");
    }

    // Tier 2 — comprobante anterior a la corrección más reciente (anulado
    // vía NC motivo 01), para trazabilidad en el detalle.
    public function correctedFromSale()
    {
        return $this->belongsTo(Sale::class, "corrected_from_sale_id");
    }

    // ── Saldo disponible — nunca una columna, siempre calculado ─────────
    public function availableBalance(): float
    {
        return round(
            (float) $this->amount - (float) $this->applied_amount - (float) $this->refunded_amount,
            2
        );
    }

    // ── Recalcula y persiste status a partir de applied_amount/refunded_amount ──
    // Se llama dentro de la misma transacción que actualiza esos dos campos
    // (nunca se edita status a mano). No hace save() — el caller ya está
    // dentro de un save()/update() más amplio.
    public function refreshStatus(): void
    {
        $amount   = (float) $this->amount;
        $applied  = (float) $this->applied_amount;
        $refunded = (float) $this->refunded_amount;

        if ($refunded >= $amount) {
            $this->status = 'refunded';
        } elseif ($refunded > 0) {
            $this->status = 'partially_refunded';
        } elseif ($applied >= $amount) {
            $this->status = 'applied';
        } elseif ($applied > 0) {
            $this->status = 'partially_applied';
        } else {
            $this->status = 'pending';
        }
    }
}
