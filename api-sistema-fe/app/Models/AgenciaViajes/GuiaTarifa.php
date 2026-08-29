<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// plan-modulo-cotizaciones-reservas.md §5.3. Tenant (sin CentralConnection).
// guia_id/destino_id son FK reales dentro de la misma DB tenant, así que
// llevan belongsTo.
class GuiaTarifa extends Model
{
    protected $table = 'guia_tarifas';

    protected $fillable = [
        // Retiro del catálogo activo (29-ago-2026) — reversible, separado
        // a propósito de vigente_desde/vigente_hasta (ver comentario de la
        // migración add_activo_to_guia_tarifas_table). No filtra index()
        // (la pantalla de gestión del guía muestra todo, con badge), solo
        // el picker de GuiaController::show() y la validación al crear un
        // ítem nuevo. Mismo patrón que ProveedorTarifa::activo.
        'activo',
        'guia_id',
        'destino_id',
        'modalidad',
        'costo_diario',
        'tipo_margen',
        'margen_valor',
        'moneda',
        'vigente_desde',
        'vigente_hasta',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'costo_diario' => 'decimal:2',
        'margen_valor' => 'decimal:2',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    public function guia()
    {
        return $this->belongsTo(Guia::class, 'guia_id');
    }

    public function destino()
    {
        return $this->belongsTo(DestinoAtractivo::class, 'destino_id');
    }

    public function paqueteItems()
    {
        return $this->hasMany(PaquetePlantillaItem::class, 'guia_tarifa_id');
    }
}
