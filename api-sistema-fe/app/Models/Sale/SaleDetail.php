<?php

namespace App\Models\Sale;

use App\Models\Concerns\ConDescripcionLinea;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleDetail extends Model
{
    use HasFactory;
    use SoftDeletes;
    use ConDescripcionLinea;
    protected $table = "sale_details";
    protected $fillable = [
        "sale_id",
        "product_id",
        "product_categorie_id",

        // Tipo de afectación IGV (Catálogo 07 SUNAT)
        // Siempre como string: '10', '17', '20', '30', '40'
        "tip_afe_igv",

        // ICBPER (bolsa plástica)
        "per_icbper",
        "icbper",

        // ISC (Impuesto Selectivo al Consumo)
        "percentage_isc",
        "isc",
        
        // Régimen ISC: '01'=Al valor, '02'=Específico, '03'=Al valor/PVP
        "tipo_isc",
        'monto_isc_fijo',

        "unidad_medida",
         // Cantidades y precios

        "quantity",
        "price_base",
        "price_final",
        "discount",

         // Totales de la línea
        "subtotal",
        "igv",
        
        "mto_valor_venta",
        "mto_base_igv",
        "porcentaje_igv",
        "total_impuestos",
        // Campos requeridos por Greenter

        "description",

        // Detalle real concatenado de qué incluye la línea (ej. "Hotel Río
        // Cumbaza (2 noches) + Tour a Lamas") — vertical Agencia de Viajes,
        // Sesión 9a. Distinto de "description" (arriba), que existe desde
        // el schema original pero está muerta en la práctica.
        "descripcion_detalle",

        // T-108: texto que REEMPLAZA al título del producto (máx. 500). NULL = sale el título.
        "descripcion_linea",
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, "sale_id");
    }
    public function product()
    {
        return $this->belongsTo(Product::class, "product_id");
    }
    public function product_categorie()
    {
        return $this->belongsTo(Categorie::class, "product_categorie_id");
    }

    // Descripción de la línea en el XML que va a SUNAT (GreenterService) y en
    // el PDF, que tienen que decir lo mismo (24-oct-2026, pedido del usuario:
    // la factura de una reserva solo decía "Tour / Actividad turística").
    // Retail: descripcion_detalle está vacío, sale el título del producto como
    // siempre. Reserva de Agencia: el título más el detalle (los servicios con
    // su fecha, o el "Texto para este comprobante" que escribió el vendedor).
    // SUNAT acepta hasta 500 caracteres en la descripción de una línea.
    //
    // T-108 — si el vendedor editó la descripción de la línea (descripcion_linea),
    // ESA es la descripción, sola: reemplaza al título y al detalle de Agencia.
    public function descripcionParaSunat(): string
    {
        $propia = $this->descripcionLineaLimpia();
        if ($propia !== null) {
            return $propia;
        }

        $titulo = (string) ($this->product?->title ?? '');
        $detalle = trim((string) $this->descripcion_detalle);

        if ($detalle === '') {
            return $titulo;
        }

        return mb_substr($titulo.' - '.$detalle, 0, 500);
    }
}
