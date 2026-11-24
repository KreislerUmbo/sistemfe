<?php

namespace App\Models\Sale;

use App\Models\Concerns\ConDescripcionLinea;
use App\Models\Product\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NoteDetail extends Model
{
    use HasFactory;
    use SoftDeletes;
    use ConDescripcionLinea;

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
        // T-108: copia de sale_details.descripcion_linea (reemplaza al título).
        "descripcion_linea",
        // T-126: copia de sale_details.descripcion_detalle (Agencia): se agrega al título.
        "descripcion_detalle",
    ];

    // Lo que dice la línea en el XML de la nota **y en su PDF** (T-125: no pueden diferir).
    // GreenterService::getDetallesComprobante() llama a este método igual que en SaleDetail.
    // Orden: texto editado → description (la escribe AdvanceController en líneas de adelanto)
    // → título del producto.
    public function descripcionParaSunat(): string
    {
        $propia = $this->descripcionLineaLimpia();
        if ($propia !== null) {
            return $propia;
        }

        $description = trim((string) $this->description);
        $base = $description !== '' ? $description : (string) ($this->product?->title ?? '');

        // T-126: igual que la venta que corrige (SaleDetail): «título - detalle», máx. 500.
        $detalle = trim((string) $this->descripcion_detalle);

        return $detalle === '' ? $base : mb_substr($base.' - '.$detalle, 0, 500);
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
