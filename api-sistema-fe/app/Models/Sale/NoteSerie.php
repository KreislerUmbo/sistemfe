<?php

namespace App\Models\Sale;

use Illuminate\Database\Eloquent\Model;

// Tabla de configuración pequeña: serie a usar según (tipo_doc de la nota,
// tipo_doc_afectado del comprobante original). Ver SerieNotaResolver, que
// es el único punto de la app que debe consultar este modelo — cuando se
// construya el módulo de series personalizables, solo hay que cambiar la
// fuente de datos detrás de ese resolver.
class NoteSerie extends Model
{
    protected $table = "note_series";

    protected $fillable = [
        "tipo_doc",
        "tipo_doc_afectado",
        "serie",
    ];
}
