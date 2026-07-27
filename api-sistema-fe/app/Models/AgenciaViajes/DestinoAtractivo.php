<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Árbol autoreferenciado de 3 niveles (zona → lugar → atractivo) —
// plan-modulo-tours-catalogo.md §2. Tenant (sin CentralConnection, a
// diferencia de ProveedorTipo/Temporada de Sesión 1) — cada agencia carga
// su propio catálogo de destinos.
class DestinoAtractivo extends Model
{
    protected $table = 'destinos_atractivos';

    protected $fillable = [
        'parent_id',
        'nombre',
        'tipo',
        'descripcion',
        'fotos',
    ];

    protected $casts = [
        'fotos' => 'array',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function hijos()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
