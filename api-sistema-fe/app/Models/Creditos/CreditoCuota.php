<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Services\Creditos\Motor\Enums\EstadoCuota;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cuota del cronograma. Los acumulados se derivan del reparto del motor (1.9). */
class CreditoCuota extends Model
{
    protected $table = 'credito_cuotas';

    protected $fillable = [
        'credito_id',
        'version_cronograma',
        'numero_cuota',
        'fecha_inicio_periodo',
        'fecha_vencimiento',
        'fecha_vencimiento_original',
        'monto_capital',
        'monto_interes',
        'monto_total',
        'capital_pagado',
        'interes_pagado',
        'mora_pagada',
        'mora_condonada',
        'mora_congelada',
        'interes_condonado',
        'cargo_monto',
        'cargo_pagado',
        'estado',
        'fecha_pago',
        'dias_atraso_al_pagar',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio_periodo' => 'date',
            'fecha_vencimiento' => 'date',
            'fecha_vencimiento_original' => 'date',
            'monto_capital' => 'decimal:2',
            'monto_interes' => 'decimal:2',
            'monto_total' => 'decimal:2',
            'capital_pagado' => 'decimal:2',
            'interes_pagado' => 'decimal:2',
            'mora_pagada' => 'decimal:2',
            'mora_condonada' => 'decimal:2',
            'mora_congelada' => 'decimal:2',
            'interes_condonado' => 'decimal:2',
            'cargo_monto' => 'decimal:2',
            'cargo_pagado' => 'decimal:2',
            'estado' => EstadoCuota::class,
            'fecha_pago' => 'date',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(CreditoPagoAplicacion::class, 'cuota_id');
    }
}
