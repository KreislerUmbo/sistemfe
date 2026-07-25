<?php

namespace App\Models\Sale;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// Catálogo de referencia (SUNAT + documentos internos) — central, mismo
// criterio que NoteMotivo/DetractionCode/TaxConfig. Seed-only, sin CRUD de UI
// (ver migración 2026_07_19_150000_create_tipos_comprobante_table para el
// detalle del seed y el CHECK que protege activo_greenter → es_documento_sunat).
class TipoComprobante extends Model
{
    use CentralConnection;

    protected $table = 'tipos_comprobante';

    protected $primaryKey = 'codigo';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'codigo',
        'nombre',
        'es_documento_sunat',
        'activo_greenter',
    ];

    protected $casts = [
        'es_documento_sunat' => 'boolean',
        'activo_greenter'    => 'boolean',
    ];
}
