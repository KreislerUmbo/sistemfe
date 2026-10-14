<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Configuración del correo de cotizaciones de la agencia
// (plan-modulo-envio-correo-cotizacion.md §3.2). Una sola fila por tenant.
class ConfiguracionCorreo extends Model
{
    protected $table = 'configuracion_correo';

    protected $fillable = [
        'remitente_nombre',
        'remitente_local',
        'reply_to',
        'copia_oculta_activa',
        'copia_oculta_email',
        'asunto_plantilla',
        'cuerpo_plantilla',
        'firma',
        'adjuntar_condiciones_por_defecto',
    ];

    protected $casts = [
        'copia_oculta_activa' => 'boolean',
        'adjuntar_condiciones_por_defecto' => 'boolean',
    ];
}
