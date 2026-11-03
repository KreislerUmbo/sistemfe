<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// T-059 (CRM C4) — por dónde llegó un contacto (WhatsApp, Facebook, recomendación…). Catálogo de
// core (retail lo usará después); por ahora solo lo usa Agencia. Se agrega, renombra, ordena y
// desactiva desde Configuración, nunca se borra: lo referencian clientes y cotizaciones. «Otro» no
// tiene trato especial.
class CrmOrigenContacto extends Model
{
    public const NOMBRE_MAX = 80;

    protected $table = 'crm_origenes_contacto';

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
