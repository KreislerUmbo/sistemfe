<?php

namespace App\Http\Resources\Sale;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $igv_general = $this->resource->igv + $this->resource->igv_discount_general;
        $isc_total = $this->resource->sale_details->sum('isc');
        $icbper_total = $this->resource->sale_details->sum('icbper');
        $total_percepcion = 0;
        $total_cal = (($this->resource->total + $isc_total + $icbper_total) - ($this->resource->discount_global));

        if ($this->resource->retencion_igv == 3) { //3 es percepcion
            $total_percepcion = ($total_cal * 0.04);
        }
        $total_retencion = 0;
        if ($this->resource->retencion_igv == 1) { //1 es retencion
            $total_retencion = ($total_cal * 0.03);
        }
        $total_detraccion = 0;
        if ($this->resource->retencion_igv == 2) { //2 es detraccion
            $total_detraccion = ($total_cal * 0.04);
        }

        $total_general = round((($total_cal + $isc_total + $total_percepcion) - ($this->resource->discount_global + $this->resource->igv_discount_general + $total_retencion + $total_detraccion)), 2);

        return [
            'id' => $this->resource->id,
            'n_transaction' => str_pad($this->resource->id, 8, "0", STR_PAD_LEFT),
            'serie' => $this->resource->serie,
            'correlativo' => $this->resource->correlativo,
            'n_operacion' => $this->resource->n_operacion,
            'user' => [
                'id' => $this->resource->user->id,
                'full_name' => $this->resource->user->name . ' ' . $this->resource->user->surname,
            ],
            'client_id' => $this->resource->client_id,
            'client' => [
                'id' => $this->resource->client->id,
                'n_document' => $this->resource->client->n_document,
                'full_name' => $this->resource->client->full_name,
                'type_client' => $this->resource->client->type_client,
            ],
            'type_client' => $this->resource->type_client, //1 es cliente normal y 2 es empresa es diferente de client porque puede cambiar con el tiempo

            'sub_total' => $this->resource->sub_total,
            'total' => $this->resource->total,
            'igv' => $this->resource->igv,
            'state_sale' => $this->resource->state_sale, //cotizacion o venta
            'state_payment' => $this->resource->state_payment, //pendiente,parcial,completo
            'type_payment' => $this->resource->type_payment, //1 es al contado y 2 es credito
            'debt' => $this->resource->debt, //deuda
            'paid_aout' => $this->resource->paid_aout, //monto pagado

            'igv_general' => $igv_general,
            'total_general' => $total_general,

            'description' => $this->resource->description,
            'discount' => $this->resource->discount,
            'retencion_igv' => $this->resource->retencion_igv, //si fue retencion,detraccion,percepcion
            'discount_global' => $this->resource->discount_global,
            'igv_discount_general' => $this->resource->igv_discount_general,
            'n_compobante_anticipo' => $this->resource->n_compobante_anticipo,
            // 'sales_anticipo' => $this->resource->sales_anticipo? json_decode($this->resource->sales_anticipo) : null,
            'amount_anticipo' => $this->resource->amount_anticipo,
            'cdr' => $this->resource->cdr ? env("APP_URL_XML_CDR") .  $this->resource->cdr : null,
            "xml" => $this->resource->xml ? env("APP_URL_XML_CDR") .  $this->resource->xml : null,
            'is_exportacion' => $this->resource->is_exportacion,
            'currency' => $this->resource->currency,    //1 es soles y 2 es dolares
            'created_at' => $this->resource->created_at->format('Y-m-d h:i A'),
            'created_at_format' => $this->resource->created_at->format('Y-m-d'),

            'sale_details' => $this->resource->sale_details->map(function ($sale_detail) {
                return SaleDetailResource::make($sale_detail);
            }),

            'sale_payments' => $this->resource->sale_payments->map(function ($sale_payment) {
                return [
                    'id' => $sale_payment->id,
                    'amount' => $sale_payment->amount,
                    'method_payment' => $sale_payment->method_payment,
                    'date_payment' => $sale_payment->date_payment ? Carbon::parse($sale_payment->date_payment)->format('Y-m-d') : null
                ];
            }),
            // 'notas'=>[];
        ];
    }
}
