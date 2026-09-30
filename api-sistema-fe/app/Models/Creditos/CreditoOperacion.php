<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use Illuminate\Database\Eloquent\Model;

/** Registro de idempotencia de una escritura del módulo (03-api.md, decisión 1). */
class CreditoOperacion extends Model
{
    protected $table = 'credito_operaciones';

    protected $fillable = [
        'clave',
        'operacion',
        'credito_id',
        'usuario_id',
        'hash_solicitud',
        'respuesta',
    ];

    protected function casts(): array
    {
        return [
            'respuesta' => 'array',
        ];
    }
}
