<?php

namespace App\Models\Sale;

use App\Models\Client\Client;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Luecano\NumeroALetras\NumeroALetras;

class Note extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $table = "notes";

    // sunat_sent_at debe ser Carbon (no string) para poder llamar ->format()
    // en las plantillas PDF y en QrCodeService::generarQrNota() — sin este
    // cast, optional($this->sunat_sent_at)->format(...) devuelve null en
    // silencio (Optional::__call solo invoca el método si el valor es un
    // objeto), dejando la fecha vacía en el PDF y en el string del QR.
    protected $casts = [
        "sunat_sent_at" => "datetime",
    ];

    protected $fillable = [
        "sale_id",

        // ── Identificación de la nota ──────────────────────────────────
        "tipo_doc", // '07' Nota de Crédito, '08' Nota de Débito

        // ── Referencia al comprobante afectado (congelada al crear) ─────
        "tipo_doc_afectado",     // '01' factura, '03' boleta
        "serie_afectada",
        "correlativo_afectado",

        // ── Identificación propia de la nota ─────────────────────────────
        "serie",
        "correlativo",     // null hasta que se envía a SUNAT (reservarCorrelativoNota)
        "n_operacion",

        // ── Motivo (Catálogo 09 o 10 SUNAT — ver note_motivos) ───────────
        "cod_motivo",
        "des_motivo",

        "tipo_afectacion", // 'total' | 'parcial'

        // ── Cliente y moneda (copiados de la venta al crear) ─────────────
        "client_id",
        "cod_tipo_doc_cliente",
        "currency",

        // ── Totales para Greenter — calculados en servidor ───────────────
        "mto_oper_gravadas",
        "mto_oper_exoneradas",
        "mto_oper_inafectas",
        "mto_oper_exportacion",
        "mto_oper_gratuitas",
        "mto_igv",
        "icbper_total",
        "isc_total",
        "ivap_total",
        "total_impuestos",
        "valor_venta",
        "mto_imp_venta",
        "redondeo",

        "reponer_stock",

        // ── Estado y rechazo SUNAT ────────────────────────────────────────
        "status", // 'pendiente' | 'enviando' | 'aceptado' | 'rechazado'
        "sunat_error_code",
        "sunat_error_message",
        "sunat_sent_at",

        // ── Facturación electrónica ────────────────────────────────────────
        "xml",
        "cdr",
        "hash_cpe",

        // ── Auditoría ──────────────────────────────────────────────────────
        "user_id",
        "sunat_sent_by_user_id",
    ];

    // ── Timestamps en zona Lima (mismo patrón que Sale) ────────────────────
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
    public function sale()
    {
        return $this->belongsTo(Sale::class, "sale_id");
    }

    public function client()
    {
        return $this->belongsTo(Client::class, "client_id");
    }

    public function user()
    {
        return $this->belongsTo(User::class, "user_id");
    }

    public function sunat_sent_by()
    {
        return $this->belongsTo(User::class, "sunat_sent_by_user_id");
    }

    public function note_details()
    {
        return $this->hasMany(NoteDetail::class, "note_id");
    }

    // ── Resumen de totales para la representación impresa ──────────────
    // Espejo de Sale::resumenImpresion(), simplificado: las notas no
    // llevan retención/detracción/percepción, anticipo, ni saldo pendiente
    // — esos conceptos son propios de la venta, no de la nota que la
    // corrige. IMPORTANTE: respeta la moneda de la nota para el monto en
    // letras (no hardcodea "SOLES" como el armarLeyendas() original de
    // FacturacionElectronicaController).
    public function resumenImpresion(): array
    {
        return [
            'icbper_total' => round((float) $this->icbper_total, 2),
            'isc_total'    => round((float) $this->isc_total, 2),
            'igv_total'    => round((float) $this->mto_igv, 2),
            'sub_total_nota' => round((float) $this->valor_venta, 2),
            'total_nota'   => round((float) $this->mto_imp_venta, 2),

            // Simplificado respecto a Sale (que usa un campo 'destino'
            // explícito): como cada línea hereda su propio tip_afe_igv de
            // la venta original, basta con que haya monto exonerado para
            // mostrar la leyenda — es más preciso que un flag de cabecera.
            'muestra_leyenda_amazonia' => (float) $this->mto_oper_exoneradas > 0,

            'monto_en_letras' => (new NumeroALetras())->toInvoice(
                (float) $this->mto_imp_venta,
                2,
                $this->currency === 'USD' ? 'DOLARES' : 'SOLES'
            ),
        ];
    }
}
