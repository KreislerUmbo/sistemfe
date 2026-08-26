<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Configuración de formato de código por tipo de documento (tour/paquete/
// cotizacion/reserva/venta_directa) — plan-modulo-codigos-numeracion.md §6.2.
// Tenant (sin CentralConnection), una fila por tipo (constraint unique en
// `tipo`). Consumida por App\Services\AgenciaViajes\CodigoGeneradorService.
class ConfiguracionCodigo extends Model
{
    protected $table = 'configuracion_codigos';

    protected $fillable = [
        'tipo',
        'prefijo',
        'deriva_de',
        'incluye_periodo',
        'formato_periodo',
        'separador',
        'longitud_correlativo',
        'reinicio_correlativo',
        'activo',
        'updated_by',
    ];

    protected $casts = [
        'incluye_periodo' => 'boolean',
        'activo' => 'boolean',
    ];
}
