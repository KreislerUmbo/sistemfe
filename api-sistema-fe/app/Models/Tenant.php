<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * @property string $ruc              Vínculo liviano hacia companies.ruc (dentro de cada tenant DB); vive en el data JSON, no en una columna real.
 * @property string $razon_social
 * @property string $status           'activo' | 'archivado' — columna real (ver getCustomColumns()).
 * @property \Illuminate\Support\Carbon|null $fecha_archivado  Columna real.
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    protected $casts = [
        'fecha_archivado' => 'datetime',
    ];

    /**
     * Excluye tenants archivados a propósito: "archivado, no borrado" (§11.2) significa
     * que el dato del tenant viejo persiste, pero su RUC queda libre para que un tenant
     * nuevo lo reclame (ej. reemplazar un piloto/prueba por el negocio real con el mismo
     * RUC, sin tener que borrar ni tocar el archivado).
     */
    public static function findByRuc(string $ruc): ?self
    {
        return static::where('data->ruc', $ruc)
            ->where('status', '!=', 'archivado')
            ->first();
    }

    /**
     * VirtualColumn (HasDataColumn) por default solo trata 'id' como columna real —
     * cualquier otro atributo asignado se enviaría al JSON 'data' en vez de quedar en
     * la columna real de Postgres. status/fecha_archivado SÍ son columnas reales
     * (migración add_status_to_tenants_table) porque se necesitan queryables/indexables
     * para los jobs de §11.3/§11.4 (recorrer tenants activos) — a diferencia de
     * ruc/razon_social, que son solo un vínculo liviano sin necesidad de índice.
     */
    public static function getCustomColumns(): array
    {
        return array_merge(parent::getCustomColumns(), ['status', 'fecha_archivado']);
    }
}
