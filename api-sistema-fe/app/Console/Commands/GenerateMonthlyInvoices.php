<?php

namespace App\Console\Commands;

use App\Services\TenantInvoiceService;
use Illuminate\Console\Command;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'tenants:generate-monthly-invoices
        {--periodo= : Período a generar, formato YYYY-MM. Por defecto, el mes actual.}';

    protected $description = 'Genera los invoices mensuales de las suscripciones activas con facturación automática. Idempotente — seguro de reintentar o correr dos veces para el mismo período.';

    public function handle(TenantInvoiceService $service): int
    {
        $periodo = $this->option('periodo') ?? now()->format('Y-m');

        $resultado = $service->generarMensualParaActivas($periodo);

        $this->info(
            "Período {$periodo}: {$resultado['nuevas']} invoice(s) nuevo(s), " .
            "{$resultado['existentes']} ya existían (sin duplicar)."
        );

        return self::SUCCESS;
    }
}
