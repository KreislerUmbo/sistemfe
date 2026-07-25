<?php

namespace App\Models\Central;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBackup extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'path',
        'size_bytes',
        'tipo',
        'estado',
        'error_message',
        'integridad_verificada',
        'verificado_at',
    ];

    protected function casts(): array
    {
        return [
            'integridad_verificada' => 'boolean',
            'verificado_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
