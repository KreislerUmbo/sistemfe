<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Services\Creditos\Motor\Enums\ReglaLimite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Autorización de excepción a un límite de otorgamiento (1.17). */
class CreditoAutorizacion extends Model
{
    protected $table = 'credito_autorizaciones';

    protected $fillable = [
        'credito_id',
        'regla',
        'detalle',
        'motivo',
        'autorizado_por',
    ];

    protected function casts(): array
    {
        return [
            'regla' => ReglaLimite::class,
            'detalle' => 'array',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }
}
