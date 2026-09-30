<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\CargoEstado;
use App\Enums\Creditos\TipoCargo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cargo sumado a una cuota (1.18). */
class CreditoCargo extends Model
{
    protected $table = 'credito_cargos';

    protected $fillable = [
        'credito_id',
        'cuota_id',
        'tipo',
        'monto',
        'reprogramacion_id',
        'estado',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoCargo::class,
            'monto' => 'decimal:2',
            'estado' => CargoEstado::class,
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(CreditoCuota::class, 'cuota_id');
    }
}
