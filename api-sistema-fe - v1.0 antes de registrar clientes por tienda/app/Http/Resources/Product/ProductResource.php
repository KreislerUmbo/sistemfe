<?php

namespace App\Http\Resources\Product;

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
            'disponiblidad' => $this->resource->disponiblidad,//'1 es venta sin stock , 2 venta con stock',
            'state' => $this->resource->state,                  //'1 es inactivo y 2 activo',
            'unidad_medida' => $this->resource->unidad_medida,
            'stock' => $this->resource->stock,
            'include_igv' => $this->resource->include_igv,   //'1 no 2 si',
            'is_icbper' => $this->resource->is_icbper,  //'1 no y 2 si ',
            'is_ivap' => $this->resource->is_ivap,   //'1 no y 2 si',
            'percentage_isc' => $this->resource->percentage_isc,
            'is_especial_nota' => $this->resource->is_especial_nota, //'1 es no y 2 si',
            'imagen' => $this->resource->imagen ? env("APP_URL") . "storage/" . $this->resource->imagen : null,
            'created_at' => $this->resource->created_at?->format("Y-m-d h:i A") ?? null,
        ];
    }
}
