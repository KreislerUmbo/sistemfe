<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\OrigenFeriado;
use Illuminate\Database\Eloquent\Model;

/** Feriado del negocio (plan 1.4). */
class Feriado extends Model
{
    protected $table = 'feriados';

    protected $fillable = [
        'fecha',
        'descripcion',
        'origen',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'origen' => OrigenFeriado::class,
        ];
    }
}
