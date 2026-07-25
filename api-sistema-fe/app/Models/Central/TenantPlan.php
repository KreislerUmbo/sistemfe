<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantPlan extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'nombre',
        'limite_usuarios',
        'limite_comprobantes_mes',
        'limite_storage_mb',
        'precio_mensual',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }
}
