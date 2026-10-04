<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\TipoCastigo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Intervalo de castigo; fecha_reversion null = vigente (1.19, 12.12). */
class CreditoCastigo extends Model
{
    protected $table = 'credito_castigos';

    protected $fillable = [
        'credito_id',
        'fecha_castigo',
        'fecha_reversion',
        'tipo',
        'motivo',
        'motivo_reversion',
        'castigado_por',
        'revertido_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_castigo' => 'date',
            'fecha_reversion' => 'date',
            'tipo' => TipoCastigo::class,
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }
}
