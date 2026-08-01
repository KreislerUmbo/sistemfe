<?php

namespace App\Http\Resources\Product;

use App\Services\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'sku' => $this->resource->sku,
            'categorie_id' => $this->resource->categorie_id,
            'categorie' => [
                'id' => $this->resource->categorie->id,
                'title' => $this->resource->categorie->title,
            ],
            'price_general' => $this->resource->price_general,
            'price_company' => $this->resource->price_company,
            'description' => $this->resource->description,
            'is_discount' => $this->resource->is_discount, //'1 es inactivo y 2 activo',
            'max_discount' => $this->resource->max_discount,
            'disponiblidad' => $this->resource->disponiblidad, //'1 es venta sin stock , 2 venta con stock',
            'state' => $this->resource->state,                  //'1 es inactivo y 2 activo',
            'unidad_medida' => $this->resource->unidad_medida,
            'stock' => $this->resource->stock,
            'include_igv' => $this->resource->include_igv,   //'1 no 2 si',
            'is_icbper' => $this->resource->is_icbper,  //'1 no y 2 si ',
            'is_ivap' => $this->resource->is_ivap,   //'1 no y 2 si',

            'is_especial_nota' => $this->resource->is_especial_nota, //'1 es no y 2 si',
            'imagen' => StorageUrl::resolve($this->resource->imagen),

            // ── Campos SUNAT ─────────────────────────────────────────
            'tipo_bien_servicio' => $this->resource->tipo_bien_servicio ?? 'BIEN',
            'codigo_detraccion' => $this->resource->codigo_detraccion,
            'detraccion' => $this->whenLoaded('detraction', function () { // whenLoaded solo incluye esto si la relación ya está cargada”. Evita consultas adicionales y hace tu API más eficiente.
                return [
                    'codigo' => $this->detraction->codigo,
                    'nombre' => $this->detraction->nombre,
                    'tasa'   => $this->detraction->tasa_porcentaje,
                ];
            }),
            'aplica_percepcion' => $this->resource->aplica_percepcion,
            'tip_afe_igv_default' => $this->resource->tip_afe_igv_default ?? '20',
            'is_isc'          => (bool) $this->resource->is_isc,
            'tipo_isc'        => $this->resource->tipo_isc ?? '01',
            'monto_isc_fijo'  => $this->resource->monto_isc_fijo ?? 0,
            'contenido_neto_litros' => $this->resource->contenido_neto_litros,
            'percentage_isc' => $this->resource->percentage_isc,

            'created_at' => $this->resource->created_at?->format("Y-m-d h:i A") ?? null,
        ];
    }
}
