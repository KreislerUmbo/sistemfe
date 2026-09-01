<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Tours opcionales de una opcion_mayorista, distinto de items_incluidos —
// plan-modulo-cotizaciones-reservas.md §2.4. Nunca se suma automáticamente
// al total (regla de aplicación, Sesión 11). Tenant (sin CentralConnection).
// opcion_mayorista_id lleva belongsTo real (FK dentro de la misma DB tenant).
class OpcionMayoristaOpcional extends Model
{
    protected $table = 'opcion_mayorista_opcionales';

    protected $fillable = [
        'opcion_mayorista_id',
        'nombre',
        'precio_por_persona',
        'moneda',
        'incluye',
        'no_incluye',
        'contenido_tour_id',
        'contenido_tour_descripcion_snapshot',
        'contenido_tour_fotos_snapshot',
    ];

    protected $casts = [
        'precio_por_persona' => 'decimal:2',
        'contenido_tour_fotos_snapshot' => 'array',
    ];

    public function opcionMayorista()
    {
        return $this->belongsTo(OpcionMayorista::class, 'opcion_mayorista_id');
    }

    // Sesión 12e — mismo criterio que OpcionMayorista::contenidoTour().
    public function contenidoTour()
    {
        return $this->belongsTo(ContenidoTour::class, 'contenido_tour_id');
    }
}
