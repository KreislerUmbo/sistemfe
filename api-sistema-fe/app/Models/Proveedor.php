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
        'direccion', // del local
        'direccion_fiscal',
        'ciudad',
        'es_no_domiciliado',
        'pais',
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
        'es_no_domiciliado' => 'boolean',
    ];

    /**
     * Con qué se reconoce a la misma empresa en varias fichas: el documento en mayúsculas y sin
     * espacios; sin documento, la propia ficha (`id:<n>`). La usan el duplicado de comprobantes y
     * las cuentas por pagar.
     */
    public function claveDocumento(): string
    {
        $documento = mb_strtoupper(preg_replace('/\s+/u', '', (string) $this->numero_documento));

        return $documento === '' ? "id:{$this->id}" : $documento;
    }

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

    // T-134 — pendientes de limpieza. Un referencial (solo para costear) no cuenta, y un no
    // domiciliado con su documento del exterior tiene numero_documento, así que tampoco.
    public function scopeSinDocumento(Builder $query): Builder
    {
        return $query->where('es_referencial', false)
            ->whereRaw("trim(coalesce(numero_documento, '')) = ''");
    }

    /**
     * T-134 — «datos incompletos» se calcula, no se guarda: así vale para un proveedor creado
     * desde cualquier lado y desaparece solo al completar los datos.
     *
     * @return list<string> lo que falta
     */
    public function datosFaltantes(): array
    {
        if ($this->es_referencial) {
            return [];
        }

        return array_keys(array_filter([
            'documento' => blank($this->numero_documento),
            'teléfono' => blank($this->telefono) && blank($this->celular) && blank($this->whatsapp),
            'dirección' => blank($this->direccion) && blank($this->direccion_fiscal),
        ]));
    }
}
