<?php

namespace App\Models\Compras;

use Illuminate\Database\Eloquent\Model;

// T-135 (D6, D7) — periodo de anotación. Sin fila = abierto.
class CompraPeriodo extends Model
{
    protected $table = 'compra_periodos';

    public const ABIERTO = 'abierto';

    public const CERRADO = 'cerrado';

    public const FORMATO = '/^\d{4}-(0[1-9]|1[0-2])$/';

    protected $fillable = [
        'periodo', 'estado', 'cerrado_at', 'cerrado_por',
        'reabierto_at', 'reabierto_por', 'motivo_reapertura', 'historial',
    ];

    protected $casts = [
        'cerrado_at' => 'datetime',
        'reabierto_at' => 'datetime',
        'historial' => 'array',
    ];

    public function estaCerrado(): bool
    {
        return $this->estado === self::CERRADO;
    }

    /**
     * Devuelve la fila del periodo bloqueada, creándola abierta si no existía: así cerrar un
     * periodo y registrar en él a la vez se hacen uno después del otro. Solo dentro de una
     * transacción.
     */
    public static function bloquear(string $periodo): self
    {
        static::query()->insertOrIgnore([
            'periodo' => $periodo,
            'estado' => self::ABIERTO,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return static::where('periodo', $periodo)->lockForUpdate()->firstOrFail();
    }
}
