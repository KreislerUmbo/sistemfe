<?php

namespace App\Models\AgenciaViajes;

use App\Models\Compras\Compra;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// T-152 — una parte del total de una compra: reserva, salida o «general».
class CompraAsignacion extends Model
{
    protected $table = 'compra_asignaciones';

    public const DESTINO_RESERVA = 'reserva';

    public const DESTINO_SALIDA = 'salida';

    public const DESTINO_GENERAL = 'general';

    public const DESTINOS = [self::DESTINO_RESERVA, self::DESTINO_SALIDA, self::DESTINO_GENERAL];

    protected $fillable = ['compra_id', 'destino', 'reserva_id', 'salida_operativa_id', 'monto', 'user_id'];

    protected $casts = [
        'monto' => 'decimal:2',
    ];

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaOperativa::class, 'salida_operativa_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** Identifica el destino dentro de una compra. */
    public function claveDestino(): string
    {
        return self::clave($this->destino, $this->reserva_id ?? $this->salida_operativa_id);
    }

    public static function clave(string $destino, ?int $id): string
    {
        return $destino.':'.($id ?? '');
    }
}
