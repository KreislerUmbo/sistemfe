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

    // Zona/lugar → todos sus descendientes (BFS nivel por nivel), para que
    // filtrar por un nodo padre del árbol también traiga resultados de sus
    // hijos — un atractivo hoja (sin hijos) cae en el caso base y se
    // comporta igual que un match exacto. Centralizado acá (antes vivía
    // duplicado como método privado en ProveedorTarifaController) para que
    // cualquier filtro por destino en el vertical (biblioteca de
    // proveedores, biblioteca de tours) use la misma lógica.
    public static function idsConDescendientes(int $destinoAtractivoId): array
    {
        $ids = [$destinoAtractivoId];
        $nivelActual = [$destinoAtractivoId];

        while (! empty($nivelActual)) {
            $hijos = self::whereIn('parent_id', $nivelActual)->pluck('id')->all();
            if (empty($hijos)) {
                break;
            }
            $ids = array_merge($ids, $hijos);
            $nivelActual = $hijos;
        }

        return $ids;
    }
}
