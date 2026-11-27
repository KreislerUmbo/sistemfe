<?php

namespace App\Models\Product;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * T-153 — Fila del kárdex. Solo la escribe App\Services\Stock\StockService; no se edita ni se
 * borra: un error de stock se corrige con otro movimiento (ajuste).
 */
class StockMovimiento extends Model
{
    public const VENTA = 'venta';
    public const VENTA_EDICION = 'venta_edicion';
    public const VENTA_ELIMINADA = 'venta_eliminada';
    public const NC_VENTA = 'nc_venta';
    public const AJUSTE = 'ajuste';
    // Compras (T-154).
    public const COMPRA = 'compra';
    public const COMPRA_ANULADA = 'compra_anulada';
    public const NC_COMPRA = 'nc_compra';
    public const NC_COMPRA_ANULADA = 'nc_compra_anulada';

    public const UPDATED_AT = null;

    protected $table = 'stock_movimientos';

    protected $fillable = [
        'product_id', 'tipo', 'cantidad', 'costo_unitario', 'saldo',
        'documento_tipo', 'documento_id', 'motivo', 'user_id',
    ];

    protected $casts = [
        'cantidad'       => 'float',
        'costo_unitario' => 'float',
        'saldo'          => 'float',
        'created_at'     => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
