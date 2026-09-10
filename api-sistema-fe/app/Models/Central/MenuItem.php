<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Fase 1a (plan-modulo-menus-y-roles.md §3.1) — catálogo de navegación
// dinámica, compartido por giro. Ver migración create_menu_items_table.php
// para el detalle de por qué modulo_id no tiene FK real todavía.
class MenuItem extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'codigo',
        'parent_id',
        'giro',
        'modulo_id',
        'permiso_requerido',
        'label',
        'icono',
        'ruta',
        'orden',
        'tipo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('orden');
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }
}
