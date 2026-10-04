<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Models\Client\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ajuste de límites de otorgamiento por cliente (1.17). */
class CreditoClienteLimite extends Model
{
    protected $table = 'credito_cliente_limites';

    protected $fillable = [
        'cliente_id',
        'max_creditos_activos',
        'deuda_maxima',
        'bloqueado',
        'motivo_bloqueo',
        'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'deuda_maxima' => 'decimal:2',
            'bloqueado' => 'boolean',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }
}
