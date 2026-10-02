<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\Infraccion;
use App\Services\Creditos\Motor\Dto\PoliticaLimites;
use App\Services\Creditos\Motor\Dto\ResultadoLimites;
use App\Services\Creditos\Motor\Dto\ResumenCreditoCliente;
use App\Services\Creditos\Motor\Dto\SolicitudOtorgamiento;
use App\Services\Creditos\Motor\Enums\ReglaLimite;

/**
 * Reglas de otorgamiento (plan 1.17, 12.11). Solo evalúa: bloquear, pedir autorización y
 * registrarla es trabajo del servicio de Fase 3.
 */
final class EvaluadorLimites
{
    public function evaluar(SolicitudOtorgamiento $s, PoliticaLimites $p): ResultadoLimites
    {
        // 1.21: el crédito que se renueva se cancela con el nuevo; no suma deuda ni cuenta como activo.
        $otros = array_values(array_filter(
            $s->creditosActivos,
            static fn (ResumenCreditoCliente $c): bool => $c->creditoId !== $s->creditoARenovarId,
        ));

        $infracciones = [];

        if (count($otros) >= $p->maxCreditosActivos) {
            $infracciones[] = $this->politica($s, ReglaLimite::MaxCreditos, [
                'creditos_activos' => count($otros),
                'maximo' => $p->maxCreditosActivos,
            ]);
        }

        $deuda = array_sum(array_map(static fn (ResumenCreditoCliente $c): int => $c->saldoCapital, $otros))
            + $s->montoCapitalNuevo;
        if ($p->deudaMaxima !== null && $deuda > $p->deudaMaxima) {
            $infracciones[] = $this->politica($s, ReglaLimite::DeudaMaxima, [
                'deuda_con_nuevo' => $deuda,
                'maximo' => $p->deudaMaxima,
            ]);
        }

        // 12.11: el crédito a renovar SÍ cuenta para moroso (renovar a un moroso requiere autorización).
        $atrasoMaximo = array_reduce(
            $s->creditosActivos,
            static fn (int $max, ResumenCreditoCliente $c): int => max($max, $c->diasAtraso),
            0,
        );
        if ($atrasoMaximo > $p->diasAtrasoBloqueo) {
            $infracciones[] = $this->politica($s, ReglaLimite::Moroso, [
                'dias_atraso' => $atrasoMaximo,
                'maximo' => $p->diasAtrasoBloqueo,
            ]);
        }

        if ($s->clienteBloqueado) {
            $infracciones[] = $this->politica($s, ReglaLimite::Bloqueado);
        }

        // 04c: ficha exigida para prestar. Política del negocio: autorizable; en migración advierte.
        if ($s->fichaFaltante !== []) {
            $infracciones[] = $this->politica($s, ReglaLimite::FichaIncompleta, ['faltan' => $s->fichaFaltante]);
        }

        foreach ($s->garantes as $garante) {
            if ($garante->clienteId === $s->clienteId) {
                // Integridad de datos, no política: bloquea siempre, también en migración (12.11).
                $infracciones[] = new Infraccion(ReglaLimite::PropioGarante, true, false, ['garante_id' => $garante->clienteId]);
                continue;
            }
            if ($garante->diasAtrasoComoTitular > 0) {
                $infracciones[] = new Infraccion(ReglaLimite::GaranteMoroso, false, false, [
                    'garante_id' => $garante->clienteId,
                    'dias_atraso' => $garante->diasAtrasoComoTitular,
                ]);
            }
            if ($garante->garantiasActivas >= $p->maxGarantiasPorGarante) {
                $infracciones[] = new Infraccion(ReglaLimite::GaranteSaturado, false, false, [
                    'garante_id' => $garante->clienteId,
                    'garantias_activas' => $garante->garantiasActivas,
                    'maximo' => $p->maxGarantiasPorGarante,
                ]);
            }
        }

        return new ResultadoLimites($infracciones);
    }

    /**
     * Regla de política del negocio: bloquea y es autorizable; en migración solo advierte (1.20).
     *
     * @param array<string, int|list<string>|null> $detalle
     */
    private function politica(SolicitudOtorgamiento $s, ReglaLimite $regla, array $detalle = []): Infraccion
    {
        return $s->esMigracion
            ? new Infraccion($regla, false, false, $detalle)
            : new Infraccion($regla, true, true, $detalle);
    }
}
