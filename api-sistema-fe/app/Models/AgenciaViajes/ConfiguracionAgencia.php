<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Configuración general de la agencia — singleton, una sola fila por
// tenant (la migración inserta la fila default en up(), ver
// plan-modulo-maestros-iniciales.md §6.2). Tenant (sin CentralConnection).
class ConfiguracionAgencia extends Model
{
    protected $table = 'configuracion_agencia';

    protected $fillable = [
        'edad_max_infante',
        'edad_max_nino',
        'formato_descuento_pdf',
        'mostrar_descuento_como_linea',
        'dias_vigencia_cotizacion',
        'dias_limpieza_alternativas_descartadas',
        'max_pax_reserva_con_vuelo',
        'max_pax_reserva_grupo',
        'meses_margen_vencimiento_documento',
        'dias_aviso_pago_proveedor',
        'dias_cotizacion_estancada',
        'dias_aviso_vencimiento_cotizacion',
    ];

    protected $casts = [
        'mostrar_descuento_como_linea' => 'boolean',
    ];
}
