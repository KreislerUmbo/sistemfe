<?php

namespace App\Models\Central;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantRestore extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'backup_id',
        'pre_restore_backup_id',
        'estado',
        'confirm_token',
        'confirm_token_expires_at',
        'confirmed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'confirm_token_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(TenantBackup::class, 'backup_id');
    }

    public function preRestoreBackup(): BelongsTo
    {
        return $this->belongsTo(TenantBackup::class, 'pre_restore_backup_id');
    }
}
