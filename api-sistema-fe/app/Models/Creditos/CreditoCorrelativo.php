<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\TipoCorrelativo;
use Illuminate\Database\Eloquent\Model;

/** Contador de numero_credito / numero_recibo; solo lo usa CorrelativoCreditoService. */
class CreditoCorrelativo extends Model
{
    protected $table = 'credito_correlativos';

    protected $fillable = [
        'tipo',
        'prefijo',
        'ultimo_numero',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoCorrelativo::class,
            'ultimo_numero' => 'integer',
        ];
    }
}
