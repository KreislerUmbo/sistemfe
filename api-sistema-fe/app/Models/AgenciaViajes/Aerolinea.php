<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// T-015 — Catálogo central de aerolíneas, común a todas las agencias (los tenants lo leen y le
// agregan; el Super-Admin lo corrige desde el panel central). CentralConnection obligatorio:
// sin el trait consultaría la BD del tenant activo (mismo criterio que ProveedorTipo).
class Aerolinea extends Model
{
    use CentralConnection;

    protected $table = 'aerolineas';

    protected $fillable = [
        'nombre',
        'nombre_normalizado',
        'codigo_iata',
        'activa',
        'creada_por_tenant',
        'unida_a_id',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    // Clave de unicidad y de búsqueda: sin mayúsculas, tildes ni espacios sobrantes
    // ("  LaTam " = "latam", "Star Peru" = "Star Perú"). Str::ascii quita las tildes; si el nombre
    // no tiene equivalente en ASCII (otro alfabeto) queda vacío y se usa el nombre en minúsculas.
    // La migración create_aerolineas_table calcula lo mismo para la lista inicial: mantenerlas iguales.
    public static function normalizar(string $nombre): string
    {
        $limpio = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nombre)));
        $ascii = Str::ascii($limpio);

        return $ascii !== '' ? $ascii : $limpio;
    }
}
