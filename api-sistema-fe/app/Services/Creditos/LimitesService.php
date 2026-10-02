<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\RequisitoFicha;
use App\Models\Client\Client;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoAutorizacion;
use App\Models\Creditos\CreditoClienteLimite;
use App\Models\Creditos\CreditoConfiguracion;
use App\Models\Creditos\CreditoCuota;
use App\Models\User;
use App\Services\Creditos\Motor\Dto\Infraccion;
use App\Services\Creditos\Motor\Dto\PoliticaLimites;
use App\Services\Creditos\Motor\Dto\ResultadoLimites;
use App\Services\Creditos\Motor\Dto\ResumenCreditoCliente;
use App\Services\Creditos\Motor\Dto\SolicitudOtorgamiento;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\Enums\ReglaLimite;
use App\Services\Creditos\Motor\EvaluadorLimites;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Límites de otorgamiento (00 1.10) con datos de BD. Saldos y atrasos salen de los
 * acumulados guardados de las cuotas vigentes (se reescriben en cada cobro), sin reaplicar
 * pagos. Un crédito castigado cuenta como activo y bloquea al cliente (autorizable).
 */
class LimitesService
{
    private const ESTADOS_VIVOS = [CreditoEstado::Activo, CreditoEstado::Castigado];

    public function __construct(
        private readonly EvaluadorLimites $evaluador,
        private readonly Reloj $reloj,
        private readonly ClienteCreditoService $clientes,
    ) {
    }

    public function evaluar(int $clienteId, int $capitalNuevo, ?int $renovarId = null, bool $esMigracion = false): ResultadoLimites
    {
        $creditos = $this->creditosVivos($clienteId);
        $ajuste = CreditoClienteLimite::where('cliente_id', $clienteId)->first();

        return $this->evaluador->evaluar(
            new SolicitudOtorgamiento(
                $clienteId,
                $capitalNuevo,
                $creditos->map(fn (Credito $c): ResumenCreditoCliente => new ResumenCreditoCliente(
                    $c->id, $this->saldoCapital($c), $this->diasAtraso($c),
                ))->values()->all(),
                $this->estaBloqueado($ajuste, $creditos),
                [],   // garantes: módulo creditos_garantes (fase 7)
                $renovarId,
                $esMigracion,
                array_map(static fn (RequisitoFicha $r): string => $r->value, $this->clientes->fichaFaltante($clienteId)),
            ),
            $this->politica($ajuste),
        );
    }

    /** @return list<Infraccion> bloqueos del crédito que todavía no tienen autorización registrada */
    public function bloqueosSinAutorizar(Credito $credito, ResultadoLimites $resultado): array
    {
        $autorizadas = CreditoAutorizacion::where('credito_id', $credito->id)->get()
            ->map(fn (CreditoAutorizacion $a): string => $a->regla->value)->all();

        return array_values(array_filter(
            $resultado->bloqueos(),
            static fn (Infraccion $i): bool => ! ($i->autorizable && in_array($i->regla->value, $autorizadas, true)),
        ));
    }

    public function autorizar(Credito $credito, ReglaLimite $regla, string $motivo, User $usuario): CreditoAutorizacion
    {
        if ($credito->estado !== CreditoEstado::Borrador) {
            throw new HttpException(422, 'Solo se autorizan excepciones sobre un crédito en borrador.');
        }
        $infraccion = $this->evaluar($credito->cliente_id, Dinero::aCentavos($credito->monto_capital))->infraccion($regla);
        if ($infraccion === null || ! $infraccion->bloquea) {
            throw new HttpException(422, 'Esa regla no está bloqueando este crédito.');
        }
        if (! $infraccion->autorizable) {
            throw new HttpException(422, 'Esta regla no se puede autorizar.');
        }

        return CreditoAutorizacion::create([
            'credito_id' => $credito->id,
            'regla' => $regla,
            'detalle' => $infraccion->detalle,
            'motivo' => $motivo,
            'autorizado_por' => $usuario->id,
        ]);
    }

    /** @return array<string, mixed> tarjeta del cliente en el formulario de nuevo crédito (00 1.10) */
    public function resumenCliente(Client $cliente): array
    {
        $creditos = $this->creditosVivos($cliente->id);
        $ajuste = CreditoClienteLimite::where('cliente_id', $cliente->id)->first();
        $politica = $this->politica($ajuste);
        $deuda = $creditos->sum(fn (Credito $c): int => $this->saldoCapital($c));
        $pagadas = CreditoCuota::whereIn('credito_id', Credito::where('cliente_id', $cliente->id)->select('id'))
            ->where('estado', EstadoCuota::Pagada);

        return [
            'creditos_activos' => $creditos->count(),
            'max_creditos_activos' => $politica->maxCreditosActivos,
            'deuda_actual' => Dinero::aSoles($deuda),
            'deuda_maxima' => $politica->deudaMaxima === null ? null : Dinero::aSoles($politica->deudaMaxima),
            'deuda_disponible' => $politica->deudaMaxima === null ? null : Dinero::aSoles(max(0, $politica->deudaMaxima - $deuda)),
            'dias_atraso_maximo' => (int) $creditos->max(fn (Credito $c): int => $this->diasAtraso($c)),
            'cuotas_pagadas' => (clone $pagadas)->count(),
            'cuotas_pagadas_a_tiempo' => (clone $pagadas)->where('dias_atraso_al_pagar', 0)->count(),
            'bloqueado' => $this->estaBloqueado($ajuste, $creditos),
            'ficha_faltante' => array_map(
                static fn (RequisitoFicha $r): array => ['requisito' => $r->value, 'etiqueta' => $r->etiqueta()],
                $this->clientes->fichaFaltante($cliente->id),
            ),
        ];
    }

    /** @param Collection<int, Credito> $creditos */
    private function estaBloqueado(?CreditoClienteLimite $ajuste, Collection $creditos): bool
    {
        return ($ajuste?->bloqueado ?? false)
            || $creditos->contains(fn (Credito $c): bool => $c->estado === CreditoEstado::Castigado);
    }

    /** @return Collection<int, Credito> */
    private function creditosVivos(int $clienteId): Collection
    {
        return Credito::where('cliente_id', $clienteId)->whereIn('estado', self::ESTADOS_VIVOS)->get();
    }

    private function politica(?CreditoClienteLimite $ajuste): PoliticaLimites
    {
        $config = CreditoConfiguracion::actual();
        $deudaMaxima = $ajuste?->deuda_maxima ?? $config->deuda_maxima_cliente;

        return new PoliticaLimites(
            $ajuste?->max_creditos_activos ?? $config->max_creditos_activos,
            $deudaMaxima === null ? null : Dinero::aCentavos($deudaMaxima),
            $config->dias_atraso_bloqueo,
            $config->max_garantias_por_garante,
        );
    }

    private function saldoCapital(Credito $credito): int
    {
        return $credito->cuotasVigentes()->get()
            ->sum(fn (CreditoCuota $c): int => Dinero::aCentavos($c->monto_capital) - Dinero::aCentavos($c->capital_pagado));
    }

    /** Días calendario de la cuota impaga más antigua (00 1.6: el atraso es siempre calendario). */
    private function diasAtraso(Credito $credito): int
    {
        $hoy = $this->reloj->hoy();
        $masAntigua = $credito->cuotasVigentes()
            ->where('estado', EstadoCuota::Pendiente)
            ->where('fecha_vencimiento', '<', $hoy->aTexto())
            ->min('fecha_vencimiento');

        return $masAntigua === null ? 0 : max(0, CargadorCredito::fecha(new \DateTimeImmutable($masAntigua))->diasHasta($hoy));
    }
}
