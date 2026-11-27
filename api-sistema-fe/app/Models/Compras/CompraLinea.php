<?php

namespace App\Models\Compras;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// T-154 — línea de una compra. No se edita (D2): se anula la compra.
// T-162 — refleja la línea del comprobante; el producto es opcional.
class CompraLinea extends Model
{
    protected $table = 'compra_lineas';

    // Tabla 7 de SUNAT: gravado, exonerado, inafecto y exportación.
    public const AFECTACIONES = ['10', '20', '30', '40'];

    public const AFECTACION_GRAVADA = '10';

    // Unidad de una línea sin producto: servicio.
    public const UNIDAD_SERVICIO = 'ZZ';

    // Tabla 6 de SUNAT: la misma lista que ofrece la ficha del producto (`UNITS` en types/products.ts).
    public const UNIDADES = [
        '4A', 'BJ', 'BLL', 'BG', 'BO', 'BX', 'CT', 'CMK', 'CMQ', 'CMT', 'CEN', 'CY', 'CJ', 'DZN', 'DZP', 'BE', 'GLI',
        'GRM', 'GRO', 'HLT', 'LEF', 'SET', 'KGM', 'KTM', 'KWH', 'KT', 'CA', 'LBR', 'LTR', 'MWH', 'MTR', 'MTK', 'MTQ',
        'MGM', 'MLT', 'MMT', 'MMK', 'MMQ', 'MIL', 'UM', 'ONZ', 'PF', 'PK', 'PR', 'FOT', 'FTK', 'FTQ', 'C62', 'PG',
        'ST', 'INH', 'RM', 'SA', 'DR', 'STN', 'LTN', 'TNE', 'TU', 'NIU', 'ZZ', 'GLL', 'YRD', 'YDK', 'QQM',
    ];

    protected $fillable = [
        'compra_id', 'product_id', 'presentacion_id', 'factor', 'cantidad',
        'valor_unitario', 'valor_venta', 'igv', 'total', 'costo_unitario_base',
        'descripcion', 'unidad_medida', 'codigo_proveedor', 'tipo_afectacion_igv',
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
