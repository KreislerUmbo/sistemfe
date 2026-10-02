<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto diaria de la cartera; solo lectura (1.16). */
class CreditoCarteraDiaria extends Model
{
    protected $table = 'credito_cartera_diaria';

    protected $fillable = [
        'fecha_corte',
        'credito_id',
        'cliente_id',
        'asesor_id',
        'cobrador_id',
        'saldo_capital',
        'saldo_interes',
        'mora_pendiente',
        'dias_atraso',
        'rango_atraso',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_corte' => 'date',
            'saldo_capital' => 'decimal:2',
            'saldo_interes' => 'decimal:2',
            'mora_pendiente' => 'decimal:2',
            'dias_atraso' => 'integer',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }
}
