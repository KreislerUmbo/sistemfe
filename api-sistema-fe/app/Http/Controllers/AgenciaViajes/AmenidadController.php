<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Amenidad;

// Solo lectura — el catálogo (amenidades, central) no tiene CRUD desde acá
// por ahora, mismo criterio que ProveedorTipoConfigController con
// proveedor_tipos (catálogo fijo, sembrado en la propia migración).
class AmenidadController extends Controller
{
    public function index()
    {
        return response()->json(['amenidades' => Amenidad::orderBy('nombre')->get()]);
    }
}
