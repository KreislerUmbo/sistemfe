<?php

namespace App\Http\Resources\Sale;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // ── Totales de impuestos por línea ────────────────────────
        $isc_total    = $this->resource->sale_details->sum('isc');
        $icbper_total = $this->resource->sale_details->sum('icbper');

        // ── Base para cálculo de regímenes especiales ─────────────
        // Usa mto_imp_venta si ya fue calculado y guardado,
        // si no, lo calcula sobre la marcha (ventas antiguas)
        $base_regimen = $this->resource->mto_imp_venta > 0
            ? (float) $this->resource->mto_imp_venta
            : round(
                ($this->resource->total + $isc_total + $icbper_total)
                - (float) ($this->resource->discount_global ?? 0),
                2
            );

        // ── Retención / Detracción / Percepción ───────────────────
        // Usa los montos guardados en la venta si existen (nuevo sistema)
        // Si no, los calcula con las tasas antiguas (compatibilidad)
        $monto_retencion  = (float) ($this->resource->monto_retencion  ?? 0);
        $monto_detraccion = (float) ($this->resource->monto_detraccion ?? 0);
        $monto_percepcion = (float) ($this->resource->monto_percepcion ?? 0);

        // Fallback para ventas registradas antes del nuevo sistema
        if ($monto_retencion == 0 && $this->resource->retencion_igv == 1) {
            $monto_retencion = round($base_regimen * 0.03, 2);
        }
        if ($monto_detraccion == 0 && $this->resource->retencion_igv == 2) {
            // Para el listado usamos el porcentaje guardado en la venta
            $pct = (float) ($this->resource->porcentaje_detraccion ?? 0.04);
            $monto_detraccion = round($base_regimen * $pct, 2);
        }
        if ($monto_percepcion == 0 && $this->resource->retencion_igv == 3) {
            $pct = (float) ($this->resource->porcentaje_percepcion ?? 0.02);
            $monto_percepcion = round($base_regimen * $pct, 2);
        }

        // ── Total general que paga el cliente ─────────────────────
        $total_general = round(
            $base_regimen
            + $monto_percepcion
            - $monto_retencion
            - $monto_detraccion,
            2
        );

        // ── IGV para mostrar en el listado ────────────────────────
        $igv_general = (float) $this->resource->igv;

        return [
            // ── Identificación ────────────────────────────────────
            'id'           => $this->resource->id,
            'n_transaction' => $this->resource->n_transaction
                ?? str_pad($this->resource->id, 8, "0", STR_PAD_LEFT),
            'serie'        => $this->resource->serie,
            'correlativo'  => $this->resource->correlativo,
            'n_operacion'  => $this->resource->n_operacion,
            'date'         => $this->resource->date,

            // ── Usuario ───────────────────────────────────────────
            'user' => $this->resource->user ? [
                'id'        => $this->resource->user->id,
                'full_name' => $this->resource->user->name . ' ' . $this->resource->user->surname,
            ] : null,

            // ── Cliente — incluye todos los campos que usa el edit ─
            'client_id' => $this->resource->client_id,
            'client'    => $this->resource->client ? [
                'id'                  => $this->resource->client->id,
                'n_document'          => $this->resource->client->n_document,
                'full_name'           => $this->resource->client->full_name,
                'name'                => $this->resource->client->name,
                'name_comerc'         => $this->resource->client->name_comerc,
                'type_client'         => $this->resource->client->type_client,
                'type_document'       => $this->resource->client->type_document,
                'cod_tipo_doc_sunat'  => $this->resource->client->cod_tipo_doc_sunat ?? '1',
                'address'             => $this->resource->client->address,
                'phone'               => $this->resource->client->phone,
                'email'               => $this->resource->client->email,
                'ubigeo_region'       => $this->resource->client->ubigeo_region,
                'ubigeo_provincia'    => $this->resource->client->ubigeo_provincia,
                'ubigeo_distrito'     => $this->resource->client->ubigeo_distrito,
                'region'              => $this->resource->client->region,
                'provincia'           => $this->resource->client->provincia,
                'distrito'            => $this->resource->client->distrito,
                'es_amazonia'         => (bool) ($this->resource->client->es_amazonia ?? false),
            ] : null,
            'type_client' => $this->resource->type_client,

            // ── Configuración de la operación ─────────────────────
            'currency'       => $this->resource->currency ?? 'PEN',
            'is_exportacion' => $this->resource->is_exportacion,
            'destino'        => $this->resource->destino ?? 'amazonia',

            // ── Totales ───────────────────────────────────────────
            'subtotal'        => (float) $this->resource->subtotal,
            'igv'             => (float) $this->resource->igv,
            'total'           => (float) $this->resource->total,
            'discount'        => (float) ($this->resource->discount ?? 0),
            'discount_global' => (float) ($this->resource->discount_global ?? 0),
            'igv_discount_general' => (float) ($this->resource->igv_discount_general ?? 0),

            // Totales por tipo de operación (para Greenter y para el edit)
            'mto_oper_gravadas'    => (float) ($this->resource->mto_oper_gravadas ?? 0),
            'mto_oper_exoneradas'  => (float) ($this->resource->mto_oper_exoneradas ?? 0),
            'mto_oper_inafectas'   => (float) ($this->resource->mto_oper_inafectas ?? 0),
            'mto_oper_exportacion' => (float) ($this->resource->mto_oper_exportacion ?? 0),
            'mto_oper_gratuitas'   => (float) ($this->resource->mto_oper_gratuitas ?? 0),

            // Impuestos desagregados
            'isc_total'       => (float) ($this->resource->isc_total ?? $isc_total),
            'icbper_total'    => (float) ($this->resource->icbper_total ?? $icbper_total),
            'ivap_total'      => (float) ($this->resource->ivap_total ?? 0),
            'total_impuestos' => (float) ($this->resource->total_impuestos ?? 0),
            'valor_venta'     => (float) ($this->resource->valor_venta ?? 0),
            'mto_imp_venta'   => (float) ($this->resource->mto_imp_venta ?? 0),
            'redondeo'        => (float) ($this->resource->redondeo ?? 0),

            // Para el listado de ventas
            'igv_general'   => $igv_general,
            'total_general' => $total_general,

            // ── Regímenes especiales ──────────────────────────────
            // retencion_igv: 0=ninguno, 1=retención, 2=detracción, 3=percepción
            'retencion_igv'         => $this->resource->retencion_igv,

            // Retención
            'monto_retencion'       => $monto_retencion,

            // Detracción — campos necesarios para cargar el edit correctamente
            'codigo_detraccion'     => $this->resource->codigo_detraccion,
            'porcentaje_detraccion' => (float) ($this->resource->porcentaje_detraccion ?? 0),
            'monto_detraccion'      => $monto_detraccion,

            // Percepción
            'porcentaje_percepcion' => (float) ($this->resource->porcentaje_percepcion ?? 0),
            'monto_percepcion'      => $monto_percepcion,

            // ── Anticipo ──────────────────────────────────────────
            'n_comprobante_anticipo' => $this->resource->n_comprobante_anticipo,
            'amount_anticipo'        => (float) ($this->resource->amount_anticipo ?? 0),

            // ── Estado ────────────────────────────────────────────
            'state_sale'    => $this->resource->state_sale,
            'state_payment' => $this->resource->state_payment,
            'type_payment'  => $this->resource->type_payment,
            'debt'          => (float) ($this->resource->debt ?? 0),
            'paid_out'      => (float) ($this->resource->paid_out ?? 0),
            'description'   => $this->resource->description,

            // ── Facturación electrónica ───────────────────────────
            // cdr y xml: retornar la ruta directamente (sin concatenar URL)
            // El frontend arma la URL completa con getFileUrl()
            'cdr' => $this->resource->cdr,
            'xml' => $this->resource->xml,

            // ── Fechas ────────────────────────────────────────────
            'created_at'        => $this->resource->created_at?->format('Y-m-d h:i A'),
            'created_at_format' => $this->resource->created_at?->format('Y-m-d'),

            // ── Detalles (con toda la info que necesita el edit) ──
            'sale_details' => $this->resource->sale_details->map(function ($detail) {
                return SaleDetailResource::make($detail);
            }),

            // ── Pagos ─────────────────────────────────────────────
            'sale_payments' => $this->resource->sale_payments->map(function ($payment) {
                return [
                    'id'             => $payment->id,
                    'amount'         => (float) $payment->amount,
                    'method_payment' => $payment->method_payment,
                    'date_payment'   => $payment->date_payment
                        ? Carbon::parse($payment->date_payment)->format('Y-m-d')
                        : null,
                ];
            }),

            // Indica si la venta puede editarse (sin XML/CDR emitido)
            'is_locked' => !is_null($this->resource->xml) || !is_null($this->resource->cdr),

            // ── Notas de Crédito/Débito ya emitidas sobre esta venta ──
            // Campos compactos (no note_details) — suficiente para el
            // badge del listado y la tabla de "notas ya emitidas" en
            // sale/nota-create.vue. Requiere with('notes') en el
            // controlador para evitar N+1 (ver SaleController::index()/show()).
            'notes' => $this->resource->relationLoaded('notes')
                ? $this->resource->notes->map(fn ($n) => [
                    'id'              => $n->id,
                    'tipo_doc'        => $n->tipo_doc,
                    'serie'           => $n->serie,
                    'correlativo'     => $n->correlativo,
                    'n_operacion'     => $n->n_operacion,
                    'cod_motivo'      => $n->cod_motivo,
                    'des_motivo'      => $n->des_motivo,
                    'tipo_afectacion' => $n->tipo_afectacion,
                    'status'          => $n->status,
                    'mto_imp_venta'   => (float) $n->mto_imp_venta,
                ])
                : [],
        ];
    }
}
