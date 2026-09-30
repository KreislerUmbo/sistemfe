<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\ModoAsignacionCartera;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Asignación de cartera a un cobrador; vigente_hasta null = vigente (1.14). */
class CarteraAsignacion extends Model
{
    protected $table = 'cartera_asignaciones';

    protected $fillable = [
        'cobrador_id',
        'tipo',
        'referencia_id',
        'vigente_desde',
        'vigente_hasta',
        'asignado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => ModoAsignacionCartera::class,
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('vigente_hasta');
    }
}
