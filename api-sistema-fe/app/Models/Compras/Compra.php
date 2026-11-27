<?php

namespace App\Models\Compras;

use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// T-135 — comprobante recibido. Nunca se borra: se anula (D8).
class Compra extends Model
{
    protected $table = 'compras';

    public const TIPOS_NOTA = ['07', '08'];

    public const TIPO_NOTA_CREDITO = '07';

    // T-154 (D4): los que pueden detallar productos; el 07 los devuelve al proveedor.
    public const TIPOS_CON_LINEAS = ['01', '03', '04', '07', '12', '91'];

    // T-162: estos, además, admiten líneas, pero solo sin producto (no mueven stock).
    public const TIPOS_CON_LINEAS_SIN_PRODUCTO = ['02', '05', '14'];

    public const MONTOS = ['base_gravada', 'igv', 'exonerado', 'inafecto', 'otros'];

    protected $fillable = [
        'proveedor_id', 'proveedor_documento', 'clave_proveedor',
        'tipo_comprobante_codigo', 'serie', 'numero', 'fecha_emision', 'periodo',
        'moneda', 'tipo_cambio',
        'base_gravada', 'igv', 'exonerado', 'inafecto', 'otros', 'total',
        'condicion', 'fecha_vencimiento', 'compra_referencia_id', 'con_credito_fiscal',
        'adjunto_pdf', 'adjunto_xml', 'observaciones',
        'anulada_at', 'anulada_por', 'motivo_anulacion', 'user_id',
    ];

    protected $casts = [
        'fecha_emision' => 'date:Y-m-d',
        'fecha_vencimiento' => 'date:Y-m-d',
        'anulada_at' => 'datetime',
        'con_credito_fiscal' => 'boolean',
        'tipo_cambio' => 'decimal:4',
        'base_gravada' => 'decimal:2',
        'igv' => 'decimal:2',
        'exonerado' => 'decimal:2',
        'inafecto' => 'decimal:2',
        'otros' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(CompraLinea::class)->orderBy('id');
    }

    // T-136: todas, también las desaplicadas.
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AplicacionPagoProveedor::class)->orderBy('id');
    }

    // T-140: lo que el proveedor devolvió de su saldo a favor, también los anulados.
    public function reembolsos(): HasMany
    {
        return $this->hasMany(ReembolsoProveedor::class)->orderBy('id');
    }

    // T-136: los pagos que hoy le están aplicados.
    public function pagos(): BelongsToMany
    {
        return $this->belongsToMany(PagoProveedor::class, 'aplicaciones_pago_proveedor', 'compra_id', 'pago_proveedor_id')
            ->withPivot(['id', 'monto', 'monto_compra'])->wherePivotNull('desaplicada_at');
    }

    public function referencia(): BelongsTo
    {
        return $this->belongsTo(self::class, 'compra_referencia_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function anulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por')->withTrashed();
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereNull('anulada_at');
    }

    public function estaAnulada(): bool
    {
        return $this->anulada_at !== null;
    }
}
