<?php

namespace App\Console\Commands;

use App\Models\Sale\Sale;
use App\Models\Tenant;
use Illuminate\Console\Command;

// Backfill de tenants.facturacion_habilitada (PEGAR-EN-CLAUDE-CODE-
// facturacion-externa-tenant.md §1) — nunca automático en la migración, ver
// convención del proyecto (CLAUDE.md, "Backfill real ejecutado con
// aprobación explícita antes de correr"). Por defecto corre en modo
// reporte/dry-run; --apply aplica de verdad. Idempotente: omite tenants que
// ya tienen la columna seteada (no NULL).
//
// Criterio (3 grupos, acordado explícitamente con el usuario 20-ago-2026,
// NO limitado a giro=agencia_viajes):
//  1. Cualquier giro con al menos un Sale real → true (ya factura acá).
//  2. giro != agencia_viajes SIN ningún Sale (ej. retail nuevo) → true por
//     defecto: ese giro no tiene concepto de "facturación externa", dejarlo
//     en NULL no protege nada.
//  3. giro == agencia_viajes SIN ningún Sale → caso ambiguo real (puede
//     arrancar "paquete completo" o "solo operativo"), queda NULL,
//     reportado aparte como "requiere decisión manual".
class BackfillFacturacionHabilitadaTenants extends Command
{
    protected $signature = 'tenants:backfill-facturacion-habilitada
        {--apply : Aplica los cambios; sin este flag solo hace un reporte de dry-run}';

    protected $description = 'Backfill de tenants.facturacion_habilitada: true por historial de Sale real '
        . '(cualquier giro) o por giro != agencia_viajes sin historial; deja NULL, marcado para decisión '
        . 'manual, a los agencia_viajes sin historial todavía. Sin --apply, solo reporta lo que haría.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        // Incluye archivados a propósito: "archivado, no borrado" — su base física
        // sigue intacta y consultable vía $tenant->run(), no hace falta excluirlos.
        $tenants = Tenant::all();

        $filas = [];
        $aPasarATrue = 0;
        $requierenDecisionManual = 0;

        foreach ($tenants as $tenant) {
            if ($tenant->facturacion_habilitada !== null) {
                $filas[] = [
                    $tenant->id,
                    $tenant->giro,
                    'ya seteado (' . ($tenant->facturacion_habilitada ? 'true' : 'false') . ')',
                    'omitido (idempotente)',
                ];
                continue;
            }

            $tieneSale = $tenant->run(fn () => Sale::query()->exists());

            if ($tieneSale) {
                $aPasarATrue++;
                $filas[] = [$tenant->id, $tenant->giro, 'tiene Sale real', $apply ? 'aplicado → true' : 'pasaría a true'];
                if ($apply) {
                    $tenant->facturacion_habilitada = true;
                    $tenant->save();
                }
                continue;
            }

            if ($tenant->giro !== 'agencia_viajes') {
                $aPasarATrue++;
                $filas[] = [
                    $tenant->id, $tenant->giro,
                    'sin Sale, giro != agencia_viajes',
                    $apply ? 'aplicado → true' : 'pasaría a true',
                ];
                if ($apply) {
                    $tenant->facturacion_habilitada = true;
                    $tenant->save();
                }
                continue;
            }

            $requierenDecisionManual++;
            $filas[] = [
                $tenant->id, $tenant->giro,
                'sin Sale, agencia_viajes',
                '⚠ REQUIERE DECISIÓN MANUAL — queda NULL',
            ];
        }

        $this->table(['Tenant', 'Giro', 'Diagnóstico', 'Resultado'], $filas);

        if (! $apply) {
            $this->warn("Dry-run: {$aPasarATrue} tenant(s) pasarían a facturacion_habilitada=true. "
                . "{$requierenDecisionManual} tenant(s) agencia_viajes sin historial requieren decisión manual "
                . '(quedan NULL, no se tocan). Corré con --apply para aplicar de verdad.');
        } else {
            $this->info("Aplicado: {$aPasarATrue} tenant(s) actualizados a facturacion_habilitada=true. "
                . "{$requierenDecisionManual} tenant(s) agencia_viajes sin historial quedaron NULL, sin decidir.");
        }

        return self::SUCCESS;
    }
}
