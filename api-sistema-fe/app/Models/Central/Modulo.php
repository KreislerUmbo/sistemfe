<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// T-135 (D1) — catálogo de módulos activables. Se siembra por migración, sin CRUD.
class Modulo extends Model
{
    use CentralConnection;

    protected $table = 'modulos';

    protected $fillable = ['codigo', 'nombre', 'descripcion', 'giro'];
}
