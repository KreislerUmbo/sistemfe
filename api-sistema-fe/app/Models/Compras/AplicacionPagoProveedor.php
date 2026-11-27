<?php

namespace App\Models\Compras;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// T-136 — cuánto de un pago cancela qué compra. Nunca se borra: desaplicar es marcarla.
class AplicacionPagoProveedor extends Model
{
    protected $table = 'aplicaciones_pago_proveedor';

    protected $fillable = [
        'pago_proveedor_id', 'compra_id', 'monto', 'monto_compra', 'tipo_cambio', 'user_id',
        'desaplicada_at', 'desaplicada_por', 'motivo_desaplicacion',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'monto_compra' => 'decimal:2',
        'tipo_cambio' => 'decimal:4',
        'desaplicada_at' => 'datetime',
    ];

    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoProveedor::class, 'pago_proveedor_id');
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function desaplicador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'desaplicada_por')->withTrashed();
    }

    /** Las que siguen aplicadas, sea el pago vigente o esté por aprobar. */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->whereNull('desaplicada_at');
    }
}
