<?php

namespace App\Models\Sale;

use App\Models\Product\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NoteDetail extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = "note_details";

    protected $fillable = [
        "note_id",

        // null cuando es un concepto libre de ND (producto is_especial_nota=1)
        // sin relación a ningún ítem realmente vendido
        "sale_detail_id",
        "product_id",

        // ── Tasas/clasificación — heredadas tal cual de sale_details ──────
        // cuando hay sale_detail_id (histórico, nunca re-resuelto); del
        // producto actual cuando es línea libre de ND
        "tip_afe_igv",
        "porcentaje_igv",
        "tipo_isc",
        "percentage_isc",
        "monto_isc_fijo",
        "per_icbper",

        // ── Montos de línea ────────────────────────────────────────────────
        "quantity",
        "price_base",
        "price_final",
        "discount",
        "subtotal",
        "igv",
        "mto_valor_venta",
        "mto_base_igv",
        "total_impuestos",
        "icbper",
        "isc",

        "unidad_medida",
        "description",
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

    // ── Relaciones ───────────────────────────────────────────────────────
    public function note()
    {
        return $this->belongsTo(Note::class, "note_id");
    }

    public function sale_detail()
    {
        return $this->belongsTo(SaleDetail::class, "sale_detail_id");
    }

    public function product()
    {
        return $this->belongsTo(Product::class, "product_id");
    }
}
