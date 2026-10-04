<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use Illuminate\Database\Eloquent\Model;

/** Auditoría de acciones sensibles del módulo; solo inserción (decisión Fase 2). */
class CreditoAuditoria extends Model
{
    protected $table = 'credito_auditoria';

    protected $fillable = [
        'accion',
        'auditable_type',
        'auditable_id',
        'credito_id',
        'antes',
        'despues',
        'motivo',
        'usuario_id',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'antes' => 'array',
            'despues' => 'array',
        ];
    }
}
