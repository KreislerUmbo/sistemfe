<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// "Tour" — plan-modulo-cotizaciones-reservas.md §3.7 +
// plan-modulo-tours-catalogo.md §5 (confirmado 24-jul-2026: misma entidad
// que "tour", validado con documentos reales de la agencia). Tenant (sin
// CentralConnection). destino_atractivo_id lleva belongsTo real (FK dentro
// de la misma DB tenant, Sesión 2).
class PaquetePlantilla extends Model
{
    protected $table = 'paquetes_plantilla';

    protected $fillable = [
        'codigo',
        'categoria',
        'nombre',
        'descripcion',
        'fotos',
        'destino_atractivo_id',
        'duracion_horas',
        'hora_salida',
        'hora_retorno',
        'lugar_recojo',
        'no_incluye',
        'recomendaciones',
        'vuelo_incluido',
        'vuelo_aerolinea',
        'vuelo_detalle',
        'precio_venta_final',
        'vigencia_desde',
        'vigencia_hasta',
        'publicado_web',
    ];

    protected $casts = [
        'fotos' => 'array',
        'vuelo_incluido' => 'boolean',
        'precio_venta_final' => 'decimal:2',
        'vigencia_desde' => 'date',
        'vigencia_hasta' => 'date',
        'publicado_web' => 'boolean',
    ];

    public function destinoAtractivo()
    {
        return $this->belongsTo(DestinoAtractivo::class, 'destino_atractivo_id');
    }

    public function paqueteItinerario()
    {
        return $this->hasMany(TourItinerarioItem::class, 'tour_id');
    }

    public function items()
    {
        return $this->hasMany(PaquetePlantillaItem::class, 'paquete_plantilla_id');
    }
}
