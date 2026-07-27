<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Catálogo reutilizable de servicios — plan-modulo-tours-catalogo.md §3.
// Tenant (sin CentralConnection). tipo_proveedor_id referencia
// proveedor_tipos.id (central) — sin FK real de Postgres cross-DB ni
// relación Eloquent belongsTo, mismo criterio que SerieComprobante::
// tipo_comprobante_codigo. Se valida a nivel de aplicación donde haga
// falta, no acá.
class Servicio extends Model
{
    protected $table = 'servicios';

    protected $fillable = [
        'nombre',
        'tipo_proveedor_id',
    ];
}
