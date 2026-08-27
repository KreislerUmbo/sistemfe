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
        // Módulo 12 (plan-modulo-codigos-numeracion.md §6.1) — sigla única
        // de la agencia, leída por ConfiguracionCodigo para sugerir el
        // prefijo de cada tipo de documento (T/P/C/R/V + sigla).
        'sigla_comercial',
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
        // Sesión 11i — descuento configurable por agencia (% o monto), por
        // ítem del lienzo y global del resumen del cotizador.
        'permitir_descuento_item',
        'modo_descuento_item',
        'modo_descuento_global',
        'margen_minimo_aceptable_pct',
        // Sesión 11o — defaults precargados al crear un OpcionHotel nuevo,
        // editables por hotel después (ver opciones_hotel.edad_max_infante_gratis/
        // edad_max_nino_cama_adicional).
        'edad_max_infante_gratis_hotel_default',
        'edad_max_nino_cama_adicional_hotel_default',
        // Sesión perfil-agencia — condiciones generales del servicio,
        // texto propio de cada agencia, descargable aparte de la parte
        // comercial de una cotización.
        'condiciones_generales_servicio',
    ];

    protected $casts = [
        'mostrar_descuento_como_linea' => 'boolean',
        'permitir_descuento_item' => 'boolean',
    ];
}
