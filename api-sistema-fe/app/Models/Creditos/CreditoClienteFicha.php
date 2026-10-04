<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\TipoDireccion;
use App\Models\Client\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ficha de cobro del cliente (1.23). */
class CreditoClienteFicha extends Model
{
    protected $table = 'credito_cliente_fichas';

    protected $fillable = [
        'cliente_id',
        'direccion_cobro',
        'tipo_direccion',
        'referencia',
        'latitud',
        'longitud',
        'telefono_alterno',
        'ocupacion',
        'notas',
        'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo_direccion' => TipoDireccion::class,
            'latitud' => 'decimal:7',
            'longitud' => 'decimal:7',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }
}
