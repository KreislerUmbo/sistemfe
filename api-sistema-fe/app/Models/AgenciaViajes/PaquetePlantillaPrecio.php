<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Precio del tour por temporada — fila 13, fase 1a
// (plan-tours-venta-simple-costo-real.md §3.12, N16). Tenant. temporada_id
// apunta al catálogo CENTRAL `temporadas` (sin FK real cross-DB), así que
// temporada() funciona porque Temporada usa CentralConnection. Vacíos: niño →
// adulto, extranjero → nacional, costo → el del tour.
class PaquetePlantillaPrecio extends Model
{
    protected $table = 'paquete_plantilla_precios';

    protected $fillable = [
        'paquete_plantilla_id',
        'temporada_id',
        'precio_venta_adulto',
        'precio_venta_nino',
        'precio_venta_adulto_ext',
        'precio_venta_nino_ext',
        'costo_estimado_persona',
        'costo_estimado_persona_ext',
    ];

    protected $casts = [
        'temporada_id' => 'integer',
        'precio_venta_adulto' => 'decimal:2',
        'precio_venta_nino' => 'decimal:2',
        'precio_venta_adulto_ext' => 'decimal:2',
        'precio_venta_nino_ext' => 'decimal:2',
        'costo_estimado_persona' => 'decimal:2',
        'costo_estimado_persona_ext' => 'decimal:2',
    ];

    public function paquetePlantilla()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'paquete_plantilla_id');
    }

    public function temporada()
    {
        return $this->belongsTo(Temporada::class, 'temporada_id');
    }
}
