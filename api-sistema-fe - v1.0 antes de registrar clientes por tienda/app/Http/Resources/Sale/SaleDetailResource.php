<?php

namespace App\Http\Resources\Sale;

use App\Http\Resources\Product\ProductResource;
use App\Models\Product\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleDetailResource extends JsonResource
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
            'product_id' => $this->resource->product_id,
            'product' => ProductResource::make($this->resource->product),
            'product_categorie_id' => $this->resource->product_categorie_id,
            'product_categorie' => [
                'title' => $this->resource->product_categorie->title
            ],
            'unidade_medida' => $this->resource->unidad_medida,
            'quantity' => (float)$this->resource->quantity,
            'price_base' => (float)$this->resource->price_base,
            'price_final' => (float) $this->resource->price_final,
            'discount' => (float) $this->resource->discount,
            'subtotal' => (float) $this->resource->subtotal,
            'igv' => (float) $this->resource->igv,
            'description' => $this->resource->description,

            'tip_afe_igv' =>  $this->resource->tip_afe_igv,
            'per_icbper' => (float) $this->resource->per_icbper,
            'icbper' => (float) $this->resource->icbper,
            'percentage_isc' => (float) $this->resource->percentage_isc,
            'isc' => (float) $this->resource->isc,
            
        ];
    }
}
