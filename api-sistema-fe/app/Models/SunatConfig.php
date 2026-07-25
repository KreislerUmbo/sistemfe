<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SunatConfig extends Model
{
    protected $fillable = [
        'company_id',
        'ruc',
        'razon_social_sunat',
        'modo',
        'sol_usuario',
        'sol_clave',
        'certificado_path',
        'certificado_password',
        'certificado_fecha_vencimiento',
        'cuenta_bancaria_detraccion',
        'endpoint_produccion',
        'endpoint_beta',
        'activo',
    ];

    protected $casts = [
        'sol_usuario' => 'encrypted',
        'sol_clave' => 'encrypted',
        'certificado_password' => 'encrypted',
        'certificado_fecha_vencimiento' => 'date',
        'activo' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // ── Certificado propio del tenant (disco 'private', Fase B.2) ────────
    // Único punto de verdad para "¿este tenant tiene un certificado propio
    // cargado?" — usado por GreenterService::resolveCertificado() (envío real
    // a SUNAT) y por TenantSunatController (panel superadmin, al validar el
    // gate de modo=produccion antes de guardar). Nunca duplicar esta
    // condición en otro lado.
    public function tieneCertificadoPropio(): bool
    {
        return (bool) $this->certificado_path
            && Storage::disk('private')->exists($this->certificado_path);
    }

    // "Válido" = presente Y no vencido. Exigido siempre que modo='produccion'
    // (GreenterService::getSee(), y el gate de TenantSunatController al
    // guardar/subir).
    public function certificadoEsValido(): bool
    {
        return $this->tieneCertificadoPropio()
            && $this->certificado_fecha_vencimiento
            && ! $this->certificado_fecha_vencimiento->isPast();
    }
}
