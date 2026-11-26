<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// T-135 (D1) — qué módulos tiene encendidos cada tenant.
class TenantModuloOverride extends Model
{
    use CentralConnection;

    protected $table = 'tenant_modulo_overrides';

    protected $fillable = ['tenant_id', 'modulo_id', 'habilitado', 'notas'];

    protected $casts = ['habilitado' => 'boolean'];
}
