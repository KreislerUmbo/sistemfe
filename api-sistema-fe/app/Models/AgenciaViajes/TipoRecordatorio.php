<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Catálogo de qué puede generar un recordatorio —
// plan-modulo-cotizaciones-reservas.md §8bis. Tenant (sin CentralConnection),
// carga inicial vía TipoRecordatorioSeeder (standalone).
class TipoRecordatorio extends Model
{
    protected $table = 'tipos_recordatorio';

    protected $fillable = [
        'codigo',
        'nombre',
        'automatico',
    ];

    protected $casts = [
        'automatico' => 'boolean',
    ];

    public function recordatorios()
    {
        return $this->hasMany(Recordatorio::class, 'tipo_id');
    }

    public function snoozeConfigs()
    {
        return $this->hasMany(RecordatorioSnoozeConfig::class, 'tipo_id');
    }
}
