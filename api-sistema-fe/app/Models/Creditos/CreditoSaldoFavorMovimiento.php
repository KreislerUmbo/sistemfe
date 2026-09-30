<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\TipoMovimientoSaldoFavor;
use App\Models\Client\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Movimiento del saldo a favor del cliente en créditos; el saldo es la suma (1.7). */
class CreditoSaldoFavorMovimiento extends Model
{
    protected $table = 'credito_saldo_favor_movimientos';

    protected $fillable = [
        'cliente_id',
        'tipo',
        'monto',
        'pago_id',
        'cash_movement_id',
        'motivo',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoSaldoFavor::class,
            'monto' => 'decimal:2',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(CreditoPago::class, 'pago_id');
    }
}
