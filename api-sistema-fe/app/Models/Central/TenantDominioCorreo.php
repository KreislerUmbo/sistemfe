<?php

namespace App\Models\Central;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Dominio de correo de una agencia autenticado en Brevo
// (plan-modulo-envio-correo-cotizacion.md §3.1). Central: lo maneja el
// Superadmin y la API key de Brevo solo vive en el entorno central.
class TenantDominioCorreo extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_VERIFICADO = 'verificado';
    public const ESTADO_FALLIDO = 'fallido';

    protected $connection = 'central';

    protected $table = 'tenant_dominios_correo';

    protected $fillable = [
        'tenant_id',
        'dominio',
        'brevo_estado',
        'dns_records',
        'ultimo_error',
        'verificado_en',
        'ultimo_chequeo_en',
    ];

    protected function casts(): array
    {
        return [
            'dns_records' => 'array',
            'verificado_en' => 'datetime',
            'ultimo_chequeo_en' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function estaVerificado(): bool
    {
        return $this->brevo_estado === self::ESTADO_VERIFICADO;
    }
}
