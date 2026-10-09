<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Services\AgenciaViajes\CondicionesGeneralesPdfService;

// Documento separado del PDF comercial de la alternativa (decisión de
// diseño confirmada) — mismo contenido para toda cotización del tenant.
// La generación vive en CondicionesGeneralesPdfService (09-oct-2026), que
// comparte el membrete con la cotización.
class CondicionesGeneralesController extends Controller
{
    public function pdf(CondicionesGeneralesPdfService $servicio)
    {
        return $servicio->generar();
    }
}
