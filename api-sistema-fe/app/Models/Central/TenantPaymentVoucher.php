<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantPaymentVoucher extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_invoice_id',
        'path',
        'estado',
        'motivo_rechazo',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TenantInvoice::class, 'tenant_invoice_id');
    }
}
