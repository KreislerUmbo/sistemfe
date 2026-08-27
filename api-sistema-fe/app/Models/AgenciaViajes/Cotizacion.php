<?php

namespace App\Models\AgenciaViajes;

use App\Models\Client\Client;
use Illuminate\Database\Eloquent\Model;

// Header de cotización — plan-modulo-cotizaciones-reservas.md §3.1. Tenant
// (sin CentralConnection). cliente_id lleva belongsTo real a clients (core,
// App\Models\Client\Client), no un cliente propio del vertical.
//
// codigo/codigo_prefijo (Módulo 12, plan-modulo-codigos-numeracion.md,
// revisión 26-ago-2026 §11): hasta esta revisión se generaban acá mismo, vía
// un evento creating() que adivinaba el tipo ('cotizacion' vs 'venta_directa')
// a partir del string literal de codigo_prefijo ('VD'). Eso se retiró: ahora
// cada caller (CotizacionController::store()/VentaDirectaController::store())
// pide su código explícitamente a
// App\Services\AgenciaViajes\CodigoGeneradorService::generar('cotizacion'|
// 'venta_directa') ANTES de Cotizacion::create(), sin ambigüedad y sin
// necesitar una columna nueva para distinguir el origen. codigo_prefijo se
// sigue guardando (viene de ConfiguracionCodigo::prefijo en el momento de
// generar) porque CodigoGeneradorService::generarParaReserva() lo usa para
// extraer el resto del código al derivar el de la reserva (Str::after()).
//
// reservas_generadas: contador acotado por cotización (§6.4), incrementado
// por generarParaReserva() — primera reserva sin sufijo, 2da+ con "-2","-3"...
class Cotizacion extends Model
{
    protected $table = 'cotizaciones';

    protected $fillable = [
        'codigo_prefijo',
        'codigo',
        'reservas_generadas',
        'cliente_id',
        'destino',
        'fecha_viaje_desde',
        'fecha_viaje_hasta',
    ];

    protected $casts = [
        'fecha_viaje_desde' => 'date',
        'fecha_viaje_hasta' => 'date',
    ];

    public function cliente()
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }

    public function pasajeros()
    {
        return $this->hasMany(CotizacionPasajero::class, 'cotizacion_id');
    }

    public function alternativas()
    {
        return $this->hasMany(Alternativa::class, 'cotizacion_id');
    }
}
