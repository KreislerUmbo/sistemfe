<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CuentaBancaria;
use App\Models\Company;
use App\Services\StorageUrl;
use Barryvdh\DomPDF\Facade\Pdf;

// Documento separado del PDF comercial de la alternativa (decisión de
// diseño confirmada) — mismo contenido para toda cotización del tenant,
// se arma en vivo desde configuracion_agencia.condiciones_generales_servicio
// (texto enriquecido vía Quill, ya viene en HTML) + cuentas bancarias +
// datos de la empresa.
class CondicionesGeneralesController extends Controller
{
    public function pdf()
    {
        $config = ConfiguracionAgencia::first();
        $empresa = Company::first();
        $cuentasBancarias = CuentaBancaria::where('activo', true)
            ->orderBy('orden')->orderBy('id')->get();

        $pdf = Pdf::loadView('pdf.agencia-viajes.condiciones-generales', [
            'empresa' => $empresa,
            'logoUrl' => StorageUrl::resolve($empresa?->logo_horizontal),
            'config' => $config,
            'cuentasBancarias' => $cuentasBancarias,
        ]);

        return $pdf->download('Condiciones-Generales-del-Servicio.pdf');
    }
}
