<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\ResultadoGestion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gestión de cobranza (1.14). */
class CreditoGestion extends Model
{
    protected $table = 'credito_gestiones';

    protected $fillable = [
        'credito_id',
        'cobrador_id',
        'fecha_gestion',
        'resultado',
        'fecha_promesa',
        'pago_id',
        'nota',
    ];

    protected function casts(): array
    {
        return [
            'fecha_gestion' => 'datetime',
            'resultado' => ResultadoGestion::class,
            'fecha_promesa' => 'date',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(CreditoPago::class, 'pago_id');
    }
}
