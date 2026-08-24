<?php

namespace App\Models\Sale;

use App\Models\Client\Client;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Luecano\NumeroALetras\NumeroALetras;

class Sale extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $table = "sales";

    protected $fillable = [
        // ── Tipo de comprobante ────────────────────────────────────────
        // 'sale' (default) = venta normal. 'advance' = comprobante de
        // adelanto de cliente (ver módulo Adelantos) — fuerza tipo de
        // operación '0104' en FacturacionElectronicaController::enviarSunat().
        "type",

        // ── Identificación del comprobante ────────────────────────────
        "serie",            // F001 (factura) o B001 (boleta) — resuelto por SerieComprobanteService
        "tipo_comprobante_codigo", // '01' factura, '03' boleta, 'NV' nota de venta, etc. — ver tipos_comprobante
        "serie_comprobante_id",    // FK a serie_comprobantes — fila exacta usada, para reservar su correlativo
        "correlativo",      // número correlativo — reservado en enviarSunat() (fiscal) o al crear (NV)
        "n_operacion",      // serie-correlativo: ej. F001-00000001 — SOLO para documentos fiscales enviados a SUNAT
        "n_transaction",    // número interno de la venta (antes de emitir)
        "date",             // fecha de emisión

        // ── Relaciones ────────────────────────────────────────────────
        "user_id",          // vendedor que registró la venta
        "client_id",        // cliente de la venta
        "type_client",      // 1=Final, 2=Empresa, 3=Cualquiera

        // ── Tipo de documento del cliente (Catálogo 06 SUNAT) ─────────
        // Se copia del cliente al crear la venta para preservarlo
        // aunque el cliente cambie su tipo de documento después
        // '0'=Sin doc, '1'=DNI, '4'=CE, '6'=RUC, '7'=Pasaporte
        "cod_tipo_doc_cliente",

        // ── Configuración de la operación ─────────────────────────────
        // 'PEN' = soles, 'USD' = dólares (código ISO — Greenter lo requiere así)
        "currency",

        // 1 = es exportación (fuerza tip_afe_igv a '40' en todos los ítems)
        "is_exportacion",

        // Destino del bien/servicio — define si aplica Ley 27037 (Amazonía)
        // 'amazonia' → exonerado de IGV (entregado dentro de la zona Amazonía)
        // 'nacional' → gravado con IGV (sale fuera de la zona)
        "destino",

        // ── Totales básicos del comprobante ───────────────────────────
        "subtotal",         // base imponible total (sin IGV)
        "igv",              // IGV total de la venta
        "total",            // monto total a pagar por el cliente
        "discount",         // suma de descuentos por ítem
        "discount_global",  // descuento global aplicado a toda la venta
        // (deprecated — ya no se usa en cálculos, el desc. global reduce la base)
        "igv_discount_general",

        // ── Totales pre-calculados para Greenter ──────────────────────
        // Calculados en el frontend y guardados para que el backend
        // no tenga que recalcularlos al emitir el XML
        // Método Greenter: Invoice→setMtoOperGravadas/Exoneradas/Inafectas/etc.
        "mto_oper_gravadas",    // suma subtotales con tip_afe_igv='10'
        "mto_oper_exoneradas",  // suma subtotales con tip_afe_igv='20'
        "mto_oper_inafectas",   // suma subtotales con tip_afe_igv='30'
        "mto_oper_exportacion", // suma subtotales con tip_afe_igv='40'
        "mto_oper_gratuitas",   // suma subtotales con tip_afe_igv='11' al '37'

        // Impuestos desagregados (para el Invoice de Greenter)
        "isc_total",        // suma de ISC de todos los ítems
        "icbper_total",     // suma de ICBPER (bolsas plásticas) de todos los ítems
        "ivap_total",       // suma de IVAP (arroz pilado) de todos los ítems
        "total_impuestos",  // igv + isc_total + icbper_total + ivap_total

        // Totales finales del comprobante (usados directamente por Greenter)
        "valor_venta",      // Invoice→setValorVenta() = subtotales - descuento_global
        "mto_imp_venta",    // Invoice→setMtoImpVenta() = total redondeado a 1 decimal
        "redondeo",         // Invoice→setRedondeo() = diferencia por redondeo

        // ── Regímenes especiales ──────────────────────────────────────
        // 0=ninguno, 1=retención, 2=detracción, 3=percepción
        "retencion_igv",

        // Retención IGV 3% (Res. 037-2002/SUNAT)
        // Solo agentes de retención designados por SUNAT. Mínimo S/ 700.
        "monto_retencion",      // monto = mto_imp_venta × 0.03

        // Detracción SPOT (Res. 183-2004/SUNAT)
        "codigo_detraccion",     // código del bien/servicio ej: '014', '037'
        "porcentaje_detraccion", // ej: 0.0400 = 4% (viene de detraction_codes)
        "monto_detraccion",      // monto = mto_imp_venta × porcentaje_detraccion

        // Percepción (Res. 058-2006/SUNAT)
        "porcentaje_percepcion", // ej: 0.0200 = 2% (viene de tax_configs)
        "monto_percepcion",      // monto = mto_imp_venta × porcentaje_percepcion

        // ── Anticipo ──────────────────────────────────────────────────
        "n_comprobante_anticipo", // número del comprobante de anticipo previo
        "amount_anticipo",        // monto del anticipo ya pagado

        // ── Forma de pago y estado ────────────────────────────────────
        "type_payment",   // 1=contado, 2=crédito
        "state_sale",     // 1=venta, 2=cotización
        "state_payment",  // 1=pendiente, 2=pago parcial, 3=pagado completo
        "debt",           // saldo pendiente de pago
        "paid_out",       // monto ya pagado

        // ── Observaciones ─────────────────────────────────────────────
        "description",    // notas internas del vendedor

        // ── Facturación electrónica ───────────────────────────────────
        // Se llenan al emitir el comprobante en SUNAT (no al crear la venta)
        "cdr",            // ruta del archivo CDR (respuesta de SUNAT)
        "xml",            // ruta del archivo XML generado por Greenter
        "hash_cpe",       // hash SUNAT (DigestValue) del XML firmado, para el QR
        "type",           // venta, antipo
        // ── Rechazo SUNAT ──────────────────────────────────────────────
        // Antes se perdía (solo se devolvía una vez en el response
        // transitorio) — ver enviarSunat(), rama de rechazo/excepción.
        "sunat_error_code",
        "sunat_error_message",
        "sunat_sent_at",

        // ── Módulo Amortizaciones (ver plan-modulo-amortizaciones.md §2.1) ──
        "condicion_pago",     // 'contado' | 'credito'
        "credit_type",        // 'cuotas_fijas' | 'libre', solo si condicion_pago='credito'
        "aplica_mora",
        "tasa_mora",          // null = usa default de companies
        "tipo_mora",          // 'fijo_por_cuota' | 'porcentaje_diario' | 'porcentaje_fijo_unico'
        "fecha_limite_pago",  // solo relevante en credit_type='libre' con aplica_mora=true
        "saldo_pendiente",    // cacheado, se recalcula en cada aplicación de pago (Fase 3-4)
        "replaces_sale_id",   // venta nueva → venta que reemplaza (§3.13)
    ];

    protected $casts = [
        "sunat_sent_at" => "datetime",
        "aplica_mora" => "boolean",
        "fecha_limite_pago" => "date",
    ];

    public function isEditable(): bool
    {
        // Si tiene CDR o estado SUNAT es 'ACEPTADO', no se puede editar
        return is_null($this->cdr);
    }

    // ── Siguiente número de transacción interno ────────────────────────
    // No confundir con el correlativo SUNAT (ese lo reserva
    // FacturacionElectronicaController::reservarCorrelativo()). Usa
    // withTrashed() + MAX() sobre TODA la tabla (no "la última fila por
    // id") para que un borrado (soft o forceDelete) nunca haga que el
    // contador retroceda y reparta un número ya usado — antes,
    // AdvanceController::store() ni siquiera seteaba n_transaction, así
    // que si la venta más reciente por id era un adelanto, la siguiente
    // venta calculaba null + 1 = 1 y salía "00000001" (ver incidente
    // 2026-07-12, memoria del proyecto — módulo Adelantos).
    public static function siguienteNumeroTransaccion(): string
    {
        $max = static::withTrashed()
            ->whereNotNull('n_transaction')
            ->selectRaw("MAX(NULLIF(n_transaction, '')::integer) as max_val")
            ->value('max_val');

        return str_pad((string) ((int) ($max ?? 999) + 1), 8, '0', STR_PAD_LEFT);
    }

    // ── Protección: nunca destruir permanentemente un comprobante ya
    // enviado a SUNAT ───────────────────────────────────────────────────
    // Un forceDelete() aquí libera su correlativo/n_transaction para que
    // se le vuelva a repartir a otro comprobante — causó una colisión
    // real de correlativo con SUNAT el 2026-07-12 (dos comprobantes
    // distintos terminaron con el mismo F001-XX). Si de verdad hay que
    // purgar una fila de prueba con n_operacion asignado, hacerlo a mano
    // en la BD, nunca vía Eloquent.
    public function forceDelete()
    {
        if ($this->n_operacion) {
            throw new \RuntimeException(
                "No se puede eliminar permanentemente la venta #{$this->id} " .
                "({$this->n_operacion}): ya fue enviada a SUNAT y borrarla " .
                "liberaría su correlativo para que se reasigne por error."
            );
        }

        return parent::forceDelete();
    }


    // ── Timestamps en zona Lima ───────────────────────────────────────
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

    // ── Relaciones ────────────────────────────────────────────────────
    public function client()
    {
        return $this->belongsTo(Client::class, "client_id");
    }

    public function user()
    {
        return $this->belongsTo(User::class, "user_id");
    }

    public function sale_payments()
    {
        return $this->hasMany(SalePayment::class, "sale_id");
    }

    public function sale_details()
    {
        return $this->hasMany(SaleDetail::class, "sale_id");
    }

    public function notes()
    {
        return $this->hasMany(Note::class, "sale_id");
    }

    // Adelantos aplicados a ESTA venta (venta final que descuenta el
    // anticipo) — no confundir con el caso donde esta venta ES el
    // comprobante de un adelanto (type='advance'), que se referencia desde
    // Advance::sale(), no desde acá.
    public function advance_applications()
    {
        return $this->hasMany(\App\Models\Advance\AdvanceApplication::class, "sale_id");
    }

    // ── Módulo Amortizaciones (plan-modulo-amortizaciones.md §6.2) ─────
    public function installments()
    {
        return $this->hasMany(\App\Models\Credit\Installment::class, "sale_id");
    }

    public function paymentApplications()
    {
        return $this->hasMany(\App\Models\Credit\PaymentApplication::class, "sale_id");
    }

    // Venta original que ESTA venta reemplaza (§3.13) — solo tiene valor si
    // esta fila nació como reemplazo de un comprobante anulado.
    public function replacesSale()
    {
        return $this->belongsTo(Sale::class, "replaces_sale_id");
    }

    // Inverso: venta(s) que reemplazaron a ESTA. hasMany (no hasOne) porque
    // replaces_sale_id no tiene constraint de unicidad a nivel de BD.
    public function replacedBy()
    {
        return $this->hasMany(Sale::class, "replaces_sale_id");
    }

    // ── ¿Ya tiene una Nota de Crédito TOTAL aceptada? ───────────────────
    // Una NC total aceptada bloquea cualquier nota adicional (NC o ND,
    // total o parcial) sobre esta venta — ver plan, "Integridad
    // referencial". Se consulta en NotaElectronicaController::store() y,
    // de forma autoritativa bajo lock, en enviarNotaSunat().
    public function tieneNotaCreditoTotalAceptada(): bool
    {
        return $this->notes()
            ->where('tipo_doc', '07')
            ->where('tipo_afectacion', 'total')
            ->where('status', 'aceptado')
            ->exists();
    }

    // Primer pago de la venta (para mostrar en el PDF)
    public function getFirstPaymentAttribute()
    {
        return $this->sale_payments()->first() ?? null;
    }

    // ── Resumen de totales para la representación impresa ─────────────
    // Centraliza el cálculo que antes estaba duplicado inline en
    // pdf_sale.blade.php, para que las plantillas nuevas (A4 y ticket
    // 80mm) usen exactamente los mismos números sin repetir la lógica.
    //
    // IMPORTANTE: $this->total YA es el total final correcto — el frontend
    // (sale/register.vue y sale/edit.vue) ya le sumó IGV post-descuento,
    // ICBPER, ISC y percepción, y le restó descuento global, retención y
    // detracción antes de persistirlo. Este método NO debe volver a sumar/
    // restar ninguno de esos conceptos sobre $this->total — solo los usa
    // para mostrar el desglose informativo en el PDF. Lo único que
    // $this->total no incluye es el anticipo (amount_anticipo), que se
    // resta acá. (Bug anterior: sumaba ICBPER/ISC de nuevo y recalculaba
    // retención/detracción/percepción con tasas fijas hardcodeadas sobre
    // el total ya neto, duplicando esos montos — ver conversación 2026-07-09.)
    public function resumenImpresion(): array
    {
        $retencion_igv_total  = 0;
        $percepcion_igv_total = 0;

        switch ($this->retencion_igv) {
            case 1: // Retención
                $retencion_igv_total = round((float) $this->monto_retencion, 2);
                break;
            case 2: // Detracción
                $retencion_igv_total = round((float) $this->monto_detraccion, 2);
                break;
            case 3: // Percepción
                $percepcion_igv_total = round((float) $this->monto_percepcion, 2);
                break;
        }

        $icbper_total = round((float) $this->icbper_total, 2);
        $isc_total    = round((float) $this->isc_total, 2);

        $sub_total_sale = round($this->subtotal - $this->discount_global, 2);
        $igv_total      = round($this->igv - $this->igv_discount_general, 2);

        // Único ajuste real pendiente sobre $this->total: restar el
        // anticipo (nunca restado en el frontend) y, por compatibilidad
        // con ventas antiguas, el campo deprecado igv_discount_general.
        $total_sales = round(
            $this->total - (float) $this->amount_anticipo - (float) $this->igv_discount_general,
            2
        );

        return [
            'retencion_igv_total'     => $retencion_igv_total,
            'percepcion_igv_total'    => $percepcion_igv_total,
            'icbper_total'            => $icbper_total,
            'isc_total'               => $isc_total,
            'sub_total_sale'          => $sub_total_sale,
            'igv_total'               => $igv_total,
            'total_sales'             => $total_sales,

            // ── Leyenda de exoneración Amazonía (Ley 27037) ───────────
            // Solo se muestra si el destino es 'amazonia' y realmente hay
            // monto exonerado — no depende del texto guardado en 'legends'
            // del comprobante SUNAT (que solo lleva el monto en letras).
            'muestra_leyenda_amazonia' => $this->destino === 'amazonia'
                && (float) $this->mto_oper_exoneradas > 0,

            // Monto en letras — se recalcula aquí (no se persiste en BD)
            // con el mismo paquete que usa armarLeyendas() al emitir a SUNAT.
            'monto_en_letras' => (new NumeroALetras())->toInvoice(
                (float) ($this->mto_imp_venta ?: $this->total),
                2,
                $this->currency === 'USD' ? 'DOLARES' : 'SOLES'
            ),
        ];
    }

    // ── Scope de filtros múltiples para el listado ────────────────────
    public function scopeFilterMultiple(
        $query,
        $buscar_producto,
        $categorie_id,
        $buscar_id_venta,
        $buscar_cliente,
        $estado_venta,
        $tipo_pago,
        $fecha_inicio,
        $fecha_fin
    ) {
        // El comprobante de un adelanto (type='advance') no es una venta
        // normal — se ve desde su propia pantalla (módulo Adelantos). Sin
        // este filtro contaminaba el listado general con la línea de
        // producto ficticio "Adelanto a cuenta - venta futura" (hallazgo de
        // auditoría del módulo, 2026-08-21).
        $query->where('type', '!=', 'advance');

        // Filtrar por nombre del producto en los detalles
        if ($buscar_producto) {
            $query->whereHas("sale_details", function ($q) use ($buscar_producto) {
                $q->whereHas("product", function ($subq) use ($buscar_producto) {
                    $subq->where("title", "ILIKE", $buscar_producto . "%");
                });
            });
        }

        // Filtrar por categoría del producto
        if ($categorie_id) {
            $query->whereHas("sale_details", function ($q) use ($categorie_id) {
                $q->where("product_categorie_id", $categorie_id);
            });
        }

        // Filtrar por número interno de la venta
        if ($buscar_id_venta) {
            $query->where("id", "ILIKE", $buscar_id_venta . "%");
        }

        // Filtrar por nombre, teléfono o documento del cliente
        if ($buscar_cliente) {
            $query->whereHas("client", function ($q) use ($buscar_cliente) {
                $q->whereRaw(
                    "(COALESCE(clients.phone,'') || ' ' || COALESCE(clients.full_name,'') || ' ' || COALESCE(clients.n_document,'')) ILIKE ?",
                    ["%{$buscar_cliente}%"]
                );
            });
        }

        if ($estado_venta) {
            $query->where("state_sale", $estado_venta);
        }

        if ($tipo_pago) {
            $query->where("type_payment", $tipo_pago);
        }

        if ($fecha_inicio && $fecha_fin) {
            $query->whereBetween("created_at", [
                Carbon::parse($fecha_inicio)->format("Y-m-d") . " 00:00:00",
                Carbon::parse($fecha_fin)->format("Y-m-d") . " 23:59:59",
            ]);
        }

        return $query;
    }

    // ── Scope para reportes SUNAT/PLE (Módulo de series de comprobantes) ──
    // Cualquier query que alimente un futuro Registro de Ventas SUNAT/PLE
    // (todavía no construido en el sistema) DEBE usar este scope o el mismo
    // filtro — nunca inferir el tipo de documento parseando el prefijo de
    // 'serie', eso se rompe apenas exista una serie NV con un prefijo no
    // estándar. tipo_comprobante_codigo=null (ventas creadas antes de este
    // módulo, sin backfill) se trata como fiscal por compatibilidad: 'NV'
    // no existía como tipo de documento antes de este módulo, así que toda
    // venta vieja sin esta columna es, por definición, fiscal.
    //
    // Nota de venta SÍ debe incluirse en: cierre de caja (dinero real que
    // entró a caja), kardex/stock (ya descuenta inventario igual que
    // cualquier venta), y un listado general de ventas internas separado
    // del Registro de Ventas fiscal — este scope es exclusivamente para el
    // reporte fiscal/PLE, no para esos otros usos.
    public function scopeSoloDocumentosFiscales($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('tipo_comprobante_codigo')
                ->orWhere('tipo_comprobante_codigo', '<>', 'NV');
        });
    }
}
