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

    // Los 161 códigos de `UNITS` (types/products.ts), en el mismo orden: la pantalla ofrece todos.
    // Los primeros 64 son la tabla 6 de SUNAT; el resto viene de la ficha del producto.
    // `CompraUnidadesTest` falla si las dos listas se separan.
    public const UNIDADES = [
        '4A', 'BJ', 'BLL', 'BG', 'BO', 'BX', 'CT', 'CMK', 'CMQ', 'CMT', 'CEN', 'CY', 'CJ', 'DZN', 'DZP', 'BE', 'GLI',
        'GRM', 'GRO', 'HLT', 'LEF', 'SET', 'KGM', 'KTM', 'KWH', 'KT', 'CA', 'LBR', 'LTR', 'MWH', 'MTR', 'MTK', 'MTQ',
        'MGM', 'MLT', 'MMT', 'MMK', 'MMQ', 'MIL', 'UM', 'ONZ', 'PF', 'PK', 'PR', 'FOT', 'FTK', 'FTQ', 'C62', 'PG',
        'ST', 'INH', 'RM', 'SA', 'DR', 'STN', 'LTN', 'TNE', 'TU', 'NIU', 'ZZ', 'GLL', 'YRD', 'YDK', 'QQM', '126',
        '12U', '2U', '2U6', 'AM', 'BAL', 'BID', 'BLS', 'BOB', 'BOT', 'BRR', 'CAJ', 'CIL', 'CM', 'CM2', 'CM3', 'CON',
        'CRT', 'FDO', 'FRC', 'GAL', 'GLE', 'GR', 'GRU', 'HL', 'HOJ', 'JGS', 'KG', 'KG3', 'KG6', 'KGA', 'KI', 'KI6',
        'KIT', 'KL6', 'KL9', 'KM', 'KW3', 'KW6', 'KWH', 'L', 'L 6', 'LAT', 'LB', 'M', 'M 6', 'M2', 'M26', 'M3',
        'M36', 'MGA', 'MGR', 'ML', 'MLL', 'MM', 'MM2', 'MM3', 'MU', 'MWH', 'OZ', 'PAI', 'PAL', 'PAQ', 'PL', 'PLC',
        'PLG', 'PS', 'PS2', 'PS3', 'PST', 'PUL', 'PZA', 'QQ', 'QUT', 'RAM', 'RES', 'ROL', 'SAC', 'SET', 'TAM', 'TC',
        'TCS', 'TIR', 'TL', 'TLS', 'TM', 'TM3', 'TMS', 'TUB', 'U', 'U 3', 'U 6', 'U2', 'U3', 'U6', 'YD', 'YD2',
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
