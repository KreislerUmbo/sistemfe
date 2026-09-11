<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Fase 0c (plan-modulo-menus-y-roles.md §9.1, modo "sombra") — ver comentario
// completo en la migración create_permission_shadow_logs_table.php.
class PermissionShadowLog extends Model
{
    protected $table = 'permission_shadow_logs';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'user_email',
        'metodo_http',
        'ruta',
        'permiso_faltante',
    ];
}
