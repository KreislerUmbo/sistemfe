<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\AccionMoraReprogramacion;
use App\Services\Creditos\Motor\Enums\TipoCargoReprogramacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Reprogramación de fechas sin cambio de montos (1.18). */
class CreditoReprogramacion extends Model
{
    protected $table = 'credito_reprogramaciones';

    protected $fillable = [
        'credito_id',
        'motivo',
        'cargo_tipo',
        'cargo_monto',
        'accion_mora',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'cargo_tipo' => TipoCargoReprogramacion::class,
            'cargo_monto' => 'decimal:2',
            'accion_mora' => AccionMoraReprogramacion::class,
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(CreditoReprogramacionCuota::class, 'reprogramacion_id');
    }
}
