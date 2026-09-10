<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Fase 1a (plan-modulo-menus-y-roles.md §9.6) — ver comentario completo en
// la migración create_role_audit_logs_table.php.
class RoleAuditLog extends Model
{
    protected $table = 'role_audit_logs';

    protected $fillable = [
        'actor_user_id',
        'actor_email',
        'target_type',
        'target_id',
        'target_label',
        'accion',
        'detalle',
    ];

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
        ];
    }
}
