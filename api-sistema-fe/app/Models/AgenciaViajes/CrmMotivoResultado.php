<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// T-052 (CRM C3) — por qué se gana o se pierde una cotización. Catálogo del vertical Agencia que
// la agencia amplía desde Configuración: se agrega, renombra, ordena y desactiva, nunca se borra
// (las cotizaciones lo referencian). «Otro» se reconoce por su nombre fijo y no se renombra ni se
// desactiva: con él se exige el detalle al perder.
class CrmMotivoResultado extends Model
{
    public const TIPO_GANADA = 'ganada';

    public const TIPO_PERDIDA = 'perdida';

    public const TIPOS = [self::TIPO_GANADA, self::TIPO_PERDIDA];

    public const NOMBRE_OTRO = 'Otro';

    public const NOMBRE_MAX = 80;

    public const DETALLE_MAX = 500;

    protected $table = 'crm_motivos_resultado';

    protected $fillable = ['tipo', 'nombre', 'activo', 'orden'];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    protected $appends = ['es_otro'];

    public function esOtro(): bool
    {
        return $this->nombre === self::NOMBRE_OTRO;
    }

    public function getEsOtroAttribute(): bool
    {
        return $this->esOtro();
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function scopeOrdenados(Builder $query): Builder
    {
        return $query->orderBy('tipo')->orderBy('orden')->orderBy('id');
    }
}
