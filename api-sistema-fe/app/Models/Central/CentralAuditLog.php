<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralAuditLog extends Model
{
    // 'central' consolidada — ver comentario en CentralUser.
    protected $connection = 'central';

    // Solo se crean, nunca se editan.
    const UPDATED_AT = null;

    protected $fillable = [
        'central_user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'payload',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function centralUser(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class);
    }
}
