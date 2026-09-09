<?php

namespace App\Console\Commands;

use App\Services\TipoCambio\TipoCambioSunatSyncService;
use Illuminate\Console\Command;

class SincronizarTipoCambioSunat extends Command
{
    protected $signature = 'tipo-cambio:sincronizar-sunat {--fecha= : Fecha puntual YYYY-MM-DD, para backfill manual. Default: hoy.}';

    protected $description = 'Trae el tipo de cambio SUNAT/SBS del día (Decolecta, fallback e-api.net.pe) y lo guarda en tipo_cambio_sunat (conexión central). Idempotente — seguro de reintentar o correr dos veces el mismo día.';

    public function handle(TipoCambioSunatSyncService $service): int
    {
        $fecha = $this->option('fecha') ? \Carbon\Carbon::parse($this->option('fecha')) : null;

        $resultado = $service->sincronizar($fecha);

        if ($resultado === null) {
            $this->error('No se pudo sincronizar el tipo de cambio SUNAT — ver log (Log::critical) para el detalle. El último valor vigente no fue tocado.');

            // No se marca como fallo duro del comando: el scheduler no debe
            // generar ruido por una caída de un API externo de terceros, el
            // log ya deja constancia inequívoca (§6 pregunta 6 del plan).
            return self::SUCCESS;
        }

        $this->info("Tipo de cambio SUNAT sincronizado para {$resultado->fecha->toDateString()}: compra={$resultado->compra} venta={$resultado->venta} (fuente: {$resultado->fuente}).");

        return self::SUCCESS;
    }
}
