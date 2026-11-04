<?php

namespace App\Models\AgenciaViajes;

use App\Services\HoraPeru;
use Illuminate\Database\Eloquent\Builder;
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
        // Versión a la que reemplaza esta tarifa (mismo criterio que ProveedorTarifa, T-028).
        'version_anterior_id',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'costo_diario' => 'decimal:2',
        'margen_valor' => 'decimal:2',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    // Única definición de "vigente hoy" (activa y dentro de su rango); la misma regla
    // que ProveedorTarifa::scopeVigentes().
    public function scopeVigentes(Builder $query, ?string $dia = null): Builder
    {
        $dia ??= HoraPeru::hoyTexto();

        return $query->where('activo', true)
            ->where('vigente_desde', '<=', $dia)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $dia));
    }

    // La misma regla de scopeVigentes(), para validar una sola fila.
    public function estaVigente(?string $dia = null): bool
    {
        $dia ??= HoraPeru::hoyTexto();

        return $this->activo
            && $this->vigente_desde->toDateString() <= $dia
            && ($this->vigente_hasta === null || $this->vigente_hasta->toDateString() >= $dia);
    }

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
