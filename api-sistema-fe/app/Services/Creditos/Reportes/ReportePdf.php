<?php

declare(strict_types=1);

namespace App\Services\Creditos\Reportes;

use App\Models\Company;
use App\Models\User;
use App\Services\Creditos\Reloj;
use App\Services\StorageUrl;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentoPdf;
use Carbon\CarbonImmutable;

/**
 * PDF A4 horizontal de un reporte de Créditos (04d). Lo usan la descarga por URL firmada y el
 * aviso diario de cobranza (Fase 5), que lo genera sin request y lo marca como confidencial.
 */
final class ReportePdf
{
    /** @param array<string, mixed> $datos lo que devuelve el servicio del reporte */
    public function generar(string $reporte, array $datos, User $usuario, CarbonImmutable $generadoEn, bool $confidencial = false): DocumentoPdf
    {
        $empresa = Company::first();

        return Pdf::loadView('pdf.creditos.reporte_a4', [
            'tabla' => TablasReporte::de($reporte, $datos),
            'empresa' => $empresa,
            'logo' => StorageUrl::resolveParaPdf($empresa?->logo_horizontal),
            'generadoPor' => $usuario->name,
            'generadoEn' => $generadoEn->setTimezone(Reloj::ZONA)->format('d/m/Y H:i'),
            'confidencial' => $confidencial,
        ])->setPaper('a4', 'landscape');
    }
}
