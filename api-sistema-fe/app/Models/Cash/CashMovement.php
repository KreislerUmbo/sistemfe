<?php

namespace App\Models\Cash;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4, §5 regla #1). Estructura
// únicamente. reference_type/reference_id y counterparty_type/counterparty_id
// son polimórfico/FK "manual" (ver comentario en la migración) — sin
// relaciones Eloquent morphTo/belongsTo hacia esos targets en esta fase,
// eso se resuelve recién cuando exista lógica que los use (Fase 4+).
// Nunca editar/borrar una fila real de esta tabla (regla de integridad #1)
// — eso se hace cumplir en Fase 4, no aquí.
class CashMovement extends Model
{
    use HasFactory;

    protected $table = "cash_movements";

    protected $fillable = [
        "cash_session_id",
        "type",
        "payment_method_id",
        "direction",
        "amount",
        "currency",
        "reference_type",
        "reference_id",
        "concept_id",
        "description",
        "counterparty_type",
        "counterparty_id",
        "counterparty_name",
        "counterparty_document",
        "attachment_path",
        "corrected_movement_id",
        "corrected_by",
        "corrected_at",
        "status",
        "created_by",
    ];

    protected $casts = [
        "amount" => "decimal:2",
        "corrected_at" => "datetime",
    ];

    // T-009: moneda de un documento ('PEN', 'S/.', 'USD', '$'…) → 'PEN' | 'USD'; null si no se reconoce.
    public static function normalizarMoneda(?string $moneda): ?string
    {
        return match (strtoupper(trim((string) $moneda))) {
            'PEN', 'S/.', 'S/' => 'PEN',
            'USD', '$', 'US$' => 'USD',
            default => null,
        };
    }

    // T-009: igual que normalizarMoneda(), pero una moneda desconocida detiene el registro
    // en vez de caer en soles en silencio.
    public static function monedaOFalla(?string $moneda): string
    {
        $normalizada = self::normalizarMoneda($moneda);
        if ($normalizada === null) {
            throw new \RuntimeException("Moneda no reconocida para el movimiento de caja: \"{$moneda}\".");
        }

        return $normalizada;
    }

    public function cashSession()
    {
        return $this->belongsTo(CashSession::class, "cash_session_id");
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, "payment_method_id");
    }

    public function concept()
    {
        return $this->belongsTo(CashConcept::class, "concept_id");
    }

    public function correctedMovement()
    {
        return $this->belongsTo(CashMovement::class, "corrected_movement_id");
    }

    public function correctedByUser()
    {
        return $this->belongsTo(User::class, "corrected_by");
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, "created_by");
    }
}
