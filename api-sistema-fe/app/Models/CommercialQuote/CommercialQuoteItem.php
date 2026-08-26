<?php

namespace App\Models\CommercialQuote;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;

class CommercialQuoteItem extends Model
{
    protected $table = "commercial_quote_items";

    protected $fillable = [
        "commercial_quote_id",
        "product_id",
        "description",
        "unidad_medida",
        "quantity",
        "unit_price",
        "discount_percent",
        "subtotal",
    ];

    public function commercialQuote()
    {
        return $this->belongsTo(CommercialQuote::class, "commercial_quote_id");
    }

    public function product()
    {
        return $this->belongsTo(Product::class, "product_id");
    }
}
