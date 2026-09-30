<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\TipoPlantilla;
use Illuminate\Database\Eloquent\Model;

/** Plantilla versionada de documento; editar crea versión nueva (1.15). */
class CreditoPlantilla extends Model
{
    protected $table = 'credito_plantillas';

    protected $fillable = [
        'tipo',
        'version',
        'contenido',
        'activa',
        'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoPlantilla::class,
            'version' => 'integer',
            'activa' => 'boolean',
        ];
    }
}
