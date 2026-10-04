<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\PagoEstado;
use App\Models\Cash\CashMovement;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pago recibido. Nunca se edita ni se borra; solo referencia/observaciones (1.9). */
class CreditoPago extends Model
{
    protected $table = 'credito_pagos';

    protected $fillable = [
        'credito_id',
        'numero_recibo',
        'monto_recibido',
        'monto_aplicado',
        'monto_excedente',
        'destino_excedente',
        'fecha_pago',
        'origen',
        'es_cierre',
        'pagado_por_cliente_id',
        'payment_method_id',
        'cash_movement_id',
        'referencia',
        'observaciones',
        'clave_idempotencia',
        'estado',
        'motivo_anulacion',
        'anulado_por',
        'anulado_en',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'monto_recibido' => 'decimal:2',
            'monto_aplicado' => 'decimal:2',
            'monto_excedente' => 'decimal:2',
            'destino_excedente' => DestinoExcedente::class,
            'fecha_pago' => 'datetime',
            'origen' => OrigenPago::class,
            'es_cierre' => 'boolean',
            'estado' => PagoEstado::class,
            'anulado_en' => 'datetime',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(CreditoPagoAplicacion::class, 'pago_id');
    }

    public function pagadoPor(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'pagado_por_cliente_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }

    /** @param Builder<self> $query */
    public function scopeValidos(Builder $query): void
    {
        $query->where('estado', PagoEstado::Valido);
    }
}
