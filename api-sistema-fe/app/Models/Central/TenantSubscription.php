<?php

namespace App\Models\Central;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantSubscription extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'tenant_plan_id',
        'monto_mensual_override',
        'dia_corte',
        'dias_gracia_suspension',
        'facturacion_automatica',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'facturacion_automatica' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TenantPlan::class, 'tenant_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(TenantInvoice::class);
    }
}
