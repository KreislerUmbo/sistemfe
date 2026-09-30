<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fecha anterior y nueva de una cuota reprogramada (1.18). */
class CreditoReprogramacionCuota extends Model
{
    protected $table = 'credito_reprogramacion_cuotas';

    protected $fillable = [
        'reprogramacion_id',
        'cuota_id',
        'fecha_anterior',
        'fecha_nueva',
        'mora_congelada',
    ];

    protected function casts(): array
    {
        return [
            'fecha_anterior' => 'date',
            'fecha_nueva' => 'date',
            'mora_congelada' => 'decimal:2',
        ];
    }

    public function reprogramacion(): BelongsTo
    {
        return $this->belongsTo(CreditoReprogramacion::class, 'reprogramacion_id');
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(CreditoCuota::class, 'cuota_id');
    }
}
