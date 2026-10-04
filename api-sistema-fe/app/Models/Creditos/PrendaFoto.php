<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\MomentoFoto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto de prenda al ingreso o devolución (1.11). */
class PrendaFoto extends Model
{
    protected $table = 'prenda_fotos';

    protected $fillable = [
        'prenda_id',
        'ruta_archivo',
        'momento',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'momento' => MomentoFoto::class,
        ];
    }

    public function prenda(): BelongsTo
    {
        return $this->belongsTo(Prenda::class, 'prenda_id');
    }
}
