<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\TipoArchivoCliente;
use App\Models\Client\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Archivo de la ficha de cobro (DNI, foto, otros) (1.23). */
class CreditoClienteArchivo extends Model
{
    protected $table = 'credito_cliente_archivos';

    protected $fillable = [
        'cliente_id',
        'tipo',
        'ruta_archivo',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoArchivoCliente::class,
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }
}
