<?php

namespace App\Models\CommercialQuote;

use App\Models\Client\Client;
use App\Models\Sale\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommercialQuote extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $table = "commercial_quotes";

    // Sin esto, valid_until/converted_at llegan como string plano y
    // ?->format() explota en runtime (bug real, encontrado probando el
    // guard anti-doble-conversión con datos reales) — created_at/updated_at
    // no necesitan entrada acá, Eloquent ya los castea a Carbon por defecto.
    protected $casts = [
        "valid_until" => "date",
        "converted_at" => "datetime",
    ];

    // converted_sale_id/converted_at NO están acá a propósito — solo los
    // toca el flujo de conversión (CommercialQuoteController::marcarConvertida()),
    // nunca un store()/update() genérico.
    protected $fillable = [
        "code",
        "client_id",
        "client_name_free",
        "client_phone_free",
        "user_id",
        "currency",
        "status",
        "subtotal",
        "discount_global",
        "total",
        "valid_until",
        "observacion",
    ];

    // ── Timestamps en zona Lima (mismo patrón que Sale/Advance) ─────────
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
    public function items()
    {
        return $this->hasMany(CommercialQuoteItem::class, "commercial_quote_id");
    }

    public function anticipos()
    {
        return $this->hasMany(CommercialQuoteAnticipo::class, "commercial_quote_id");
    }

    public function client()
    {
        return $this->belongsTo(Client::class, "client_id");
    }

    public function convertedSale()
    {
        return $this->belongsTo(Sale::class, "converted_sale_id");
    }

    public function registradoPor()
    {
        return $this->belongsTo(User::class, "user_id");
    }

    // ── Correlativo interno "COT-00000001" — mismo patrón MAX+1 que
    // Sale::siguienteNumeroTransaccion(), extrayendo el número tras el
    // prefijo fijo "COT-". withTrashed() para no reusar un código de una
    // cotización borrada.
    public static function siguienteCodigo(): string
    {
        $max = static::withTrashed()
            ->selectRaw("MAX(SUBSTRING(code FROM 5)::integer) as max_val")
            ->value('max_val');

        return sprintf('COT-%08d', ((int) $max) + 1);
    }
}
