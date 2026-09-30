<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\TipoDocumentoCredito;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Contrato congelado o contrato firmado subido (1.15). */
class CreditoDocumento extends Model
{
    protected $table = 'credito_documentos';

    protected $fillable = [
        'credito_id',
        'tipo',
        'ruta_archivo',
        'plantilla_version',
        'hash',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDocumentoCredito::class,
            'plantilla_version' => 'integer',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }
}
