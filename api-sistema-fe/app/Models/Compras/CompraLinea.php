<?php

namespace App\Models\Compras;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// T-154 — línea de producto de una compra. No se edita (D2): se anula la compra.
class CompraLinea extends Model
{
    protected $table = 'compra_lineas';

    protected $fillable = [
        'compra_id', 'product_id', 'presentacion_id', 'factor', 'cantidad',
        'valor_unitario', 'valor_venta', 'igv', 'total', 'costo_unitario_base',
    ];

    protected $casts = [
        'factor' => 'decimal:6',
        'cantidad' => 'decimal:6',
        'valor_unitario' => 'decimal:6',
        'valor_venta' => 'decimal:2',
        'igv' => 'decimal:2',
        'total' => 'decimal:2',
        'costo_unitario_base' => 'decimal:6',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }
}
