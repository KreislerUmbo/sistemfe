<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Contador atómico por tipo de documento (tour/paquete/cotizacion/
// venta_directa) — plan-modulo-codigos-numeracion.md §6.3. Separado de
// ConfiguracionCodigo para que la pantalla de ajustes no compita por el
// mismo lock que la generación de códigos en caliente. `periodo` queda
// siempre null hoy (ver comentario en la migración de creación) — no se usa
// para resetear ningún correlativo todavía.
class CodigoSecuencia extends Model
{
    protected $table = 'codigo_secuencias';

    protected $fillable = [
        'tipo',
        'periodo',
        'ultimo_correlativo',
    ];
}
