<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\ConceptoCondonacion;
use App\Enums\Creditos\CondonacionEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Condonación de mora; sobrevive a los recálculos por anulación (1.6). */
class CreditoCondonacion extends Model
{
    protected $table = 'credito_condonaciones';

    protected $fillable = [
        'credito_id',
        'cuota_id',
        'concepto',
        'monto',
        'motivo',
        'estado',
        'registrado_por',
        'anulado_por',
        'anulado_en',
    ];

    protected function casts(): array
    {
        return [
            'concepto' => ConceptoCondonacion::class,
            'monto' => 'decimal:2',
            'estado' => CondonacionEstado::class,
            'anulado_en' => 'datetime',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(CreditoCuota::class, 'cuota_id');
    }
}
