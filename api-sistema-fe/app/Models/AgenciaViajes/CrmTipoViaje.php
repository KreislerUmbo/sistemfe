<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// T-060 (CRM C5) — etiqueta de «tipo de viaje» (Naturaleza, Aventura…) de las preferencias del
// viajero. Catálogo del vertical Agencia: se agrega, renombra, ordena y desactiva desde
// Configuración, nunca se borra (lo referencian los perfiles). Mismo patrón que CrmOrigenContacto.
class CrmTipoViaje extends Model
{
    public const NOMBRE_MAX = 60;

    protected $table = 'crm_tipos_viaje';

    protected $fillable = ['nombre', 'activo', 'orden'];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function scopeOrdenados(Builder $query): Builder
    {
        return $query->orderBy('orden')->orderBy('id');
    }
}
