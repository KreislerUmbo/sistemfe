<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Services\Creditos\Motor\Enums\ConceptoAplicacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parte de un pago aplicada a una cuota; vigente=false = reparto reemplazado (1.9). */
class CreditoPagoAplicacion extends Model
{
    protected $table = 'credito_pago_aplicaciones';

    protected $fillable = [
        'credito_id',
        'pago_id',
        'cuota_id',
        'concepto',
        'monto',
        'generacion',
        'vigente',
    ];

    protected function casts(): array
    {
        return [
            'concepto' => ConceptoAplicacion::class,
            'monto' => 'decimal:2',
            'generacion' => 'integer',
            'vigente' => 'boolean',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(CreditoPago::class, 'pago_id');
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(CreditoCuota::class, 'cuota_id');
    }

    /** @param Builder<self> $query */
    public function scopeVigentes(Builder $query): void
    {
        $query->where('vigente', true);
    }
}
