<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Sesión 12b — registro liviano por destino de un viaje (auditoria-
// arquitectonica-agencia-viajes.md §7). Existe ANTES que cualquier
// AlternativaItem/OpcionMayorista — 12c/12d los cuelgan de acá. Sin
// moneda/tipo de cambio propios a propósito, eso se queda en Alternativa.
class AlternativaDestino extends Model
{
    protected $table = 'alternativa_destinos';

    protected $fillable = [
        'alternativa_id',
        'destino_atractivo_id',
        'destino_texto',
        'orden',
        'fecha_inicio',
        'fecha_fin',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    public function alternativa()
    {
        return $this->belongsTo(Alternativa::class, 'alternativa_id');
    }

    public function destinoAtractivo()
    {
        return $this->belongsTo(DestinoAtractivo::class, 'destino_atractivo_id');
    }

    // Sesión 12c — mismo criterio que Alternativa::items(): orderBy('id')
    // explícito, no confiar en el orden físico de Postgres.
    public function items()
    {
        return $this->hasMany(AlternativaItem::class, 'alternativa_destino_id')->orderBy('id');
    }

    // Sesión 12c — extraído de la migración de backfill de 12b
    // (2026_09_01_100100_backfill_alternativa_destinos.php, que NO se
    // reescribe por ser historia ya aplicada) para que AlternativaController::
    // store() resuelva el destino de una alternativa nueva con el mismo
    // criterio exacto que usó el backfill histórico — case-insensitive +
    // trim contra destinos_atractivos.nombre, null si no hay match (nunca
    // un error, es el caso esperado para destinos fuera de catálogo).
    public static function resolverDestinoAtractivoId(?string $destinoTexto): ?int
    {
        if ($destinoTexto === null || trim($destinoTexto) === '') {
            return null;
        }

        return DestinoAtractivo::whereRaw('LOWER(TRIM(nombre)) = LOWER(TRIM(?))', [$destinoTexto])
            ->orderBy('id')
            ->value('id');
    }
}
