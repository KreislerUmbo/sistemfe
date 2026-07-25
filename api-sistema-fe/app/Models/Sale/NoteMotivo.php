<?php

namespace App\Models\Sale;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// Catálogo de motivos SUNAT (09 Nota de Crédito, 10 Nota de Débito) con las
// reglas permite_total/permite_parcial/solo_factura. Ver seed en la
// migración create_note_motivos_table para el detalle y origen de cada regla.
class NoteMotivo extends Model
{
    use CentralConnection;

    protected $table = "note_motivos";

    protected $fillable = [
        "catalogo",
        "codigo",
        "label",
        "permite_total",
        "permite_parcial",
        "solo_factura",
        "disponible_flujo_simple",
        "modo_parcial",
    ];

    protected $casts = [
        "permite_total"            => "boolean",
        "permite_parcial"          => "boolean",
        "solo_factura"             => "boolean",
        "disponible_flujo_simple"  => "boolean",
    ];
}
