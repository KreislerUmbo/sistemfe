<?php

namespace App\Models\Central;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantInvoice extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_subscription_id',
        'tenant_id',
        'periodo',
        'folio_interno',
        'monto',
        'fecha_vencimiento',
        'estado',
        'fecha_pago',
        'sunat_document_id',
        'aviso_gracia_enviado_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
            'fecha_pago' => 'date',
            'aviso_gracia_enviado_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(TenantPaymentVoucher::class);
    }
}
