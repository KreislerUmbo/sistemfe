<?php

namespace App\Models;

use App\Models\AgenciaViajes\ProveedorTipo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// T-133 (plan-tours-venta-simple-costo-real.md §3.7c) — el proveedor es del sistema base: un solo
// catálogo para Caja, Compras, Rendiciones y Agencia, en todos los giros. Acá van los datos
// básicos; lo turístico (servicios, tarifas, hotel, fotos) vive en la subclase
// App\Models\AgenciaViajes\Proveedor, sobre la misma tabla.
class Proveedor extends Model
{
    protected $table = 'proveedores';

    public const ALCANCE_TODOS = 'todos';

    // Un proveedor es turístico si su tipo es uno de los tipos de este giro en proveedor_tipos.
    private const GIRO_TIPO_TURISTICO = 'agencia_viajes';

    // Lo que un proveedor muestra y acepta fuera de Agencia (sin márgenes, fotos ni tipo).
    public const CAMPOS_BASICOS = [
        'razon_social',
        'nombre_comercial',
        'tipo_persona',
        'tipo_documento',
        'numero_documento',
        'direccion',
        'telefono',
        'celular',
        'email',
        'observaciones',
        'estado',
    ];

    protected $fillable = self::CAMPOS_BASICOS;

    protected $casts = [
        'estado' => 'boolean',
        'es_referencial' => 'boolean',
    ];

    /**
     * Único punto del filtro turístico/todos (plan §3.7c). Por defecto solo turísticos (las
     * pantallas de Agencia); Caja, Compras y Rendiciones piden ALCANCE_TODOS. General = sin
     * tipo, o con un tipo que no es turístico.
     */
    public function scopeDeAlcance(Builder $query, ?string $alcance = null): Builder
    {
        if ($alcance === self::ALCANCE_TODOS) {
            return $query;
        }

        return $query->whereIn(
            'tipo_id',
            ProveedorTipo::where('giro', self::GIRO_TIPO_TURISTICO)->pluck('id')
        );
    }
}
