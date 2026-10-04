<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Models\Client\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Garante de un crédito, registrado como cliente (1.10). */
class CreditoGarante extends Model
{
    protected $table = 'credito_garantes';

    protected $fillable = [
        'credito_id',
        'cliente_id',
        'observaciones',
    ];

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }
}
