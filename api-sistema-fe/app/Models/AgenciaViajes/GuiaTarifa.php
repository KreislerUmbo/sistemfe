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
}
