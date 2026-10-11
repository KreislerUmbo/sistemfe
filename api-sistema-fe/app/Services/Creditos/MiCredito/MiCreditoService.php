<?php

declare(strict_types=1);

namespace App\Services\Creditos\MiCredito;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\PagoEstado;
use App\Models\Client\Client;
use App\Models\Company;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Services\Creditos\ConsultaCreditoService;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\DetalleCredito;
use App\Services\Creditos\Reloj;
use App\Services\StorageUrl;

/**
 * Lo que ve el cliente en "Mi crédito" (Fase 5b): por cada crédito su próxima cuota (N de M, monto,
 * fecha), lo vencido con su mora, cuánto le falta y los pagos recientes. Los montos salen del mismo
 * cálculo que el detalle del crédito, el listado y la Cobranza del día
 * (ConsultaCreditoService::detallesEnBloque), nunca de una fórmula aparte. Solo lectura y sin datos
 * internos (asesor, notas, auditoría, "castigado").
 */
class MiCreditoService
{
    private const PAGOS_RECIENTES = 5;
    /** Créditos ya pagados que se siguen mostrando como "Cancelado" (los más recientes). */
    private const CANCELADOS_VISIBLES = 3;

    public function __construct(
        private readonly ConsultaCreditoService $consultas,
        private readonly Reloj $reloj,
    ) {
    }

    /** @return array<string, mixed> */
    public function datos(Client $cliente): array
    {
        $vigentes = Credito::where('cliente_id', $cliente->id)
            ->whereIn('estado', [CreditoEstado::Activo, CreditoEstado::Castigado])
            ->orderBy('fecha_desembolso')->orderBy('id')->get();
        $cancelados = Credito::where('cliente_id', $cliente->id)->where('estado', CreditoEstado::Finalizado)
            ->orderByDesc('updated_at')->limit(self::CANCELADOS_VISIBLES)->get();
        $detalles = $this->consultas->detallesEnBloque($vigentes);
        $todos = $vigentes->concat($cancelados);
        $empresa = Company::first();

        return [
            'negocio' => [
                'nombre' => $empresa?->razon_social_comercial ?: $empresa?->razon_social,
                'telefono' => $empresa?->phone,
                'logo' => $empresa?->logo_horizontal ? StorageUrl::resolve($empresa->logo_horizontal) : null,
            ],
            'cliente' => ['nombre' => $cliente->full_name ?: trim($cliente->name . ' ' . $cliente->surname)],
            'hoy' => $this->reloj->hoy()->aTexto(),
            'generado_en' => now()->toIso8601String(),
            'creditos' => [
                ...$vigentes->map(fn (Credito $c): array => $this->vigente($detalles[$c->id]))->all(),
                ...$cancelados->map(static fn (Credito $c): array => [
                    'numero' => $c->numero_credito,
                    'estado' => 'cancelado',
                    'monto_prestado' => Dinero::aSoles(Dinero::aCentavos($c->monto_capital)),
                    'cancelado_el' => $c->updated_at?->toIso8601String(),
                ])->all(),
            ],
            'pagos' => $todos->isEmpty() ? [] : CreditoPago::with('credito:id,numero_credito')
                ->whereIn('credito_id', $todos->pluck('id'))
                ->where('estado', PagoEstado::Valido)
                ->orderByDesc('fecha_pago')->orderByDesc('id')->limit(self::PAGOS_RECIENTES)->get()
                ->map(static fn (CreditoPago $p): array => [
                    // fecha_pago es fecha-hora de negocio en hora de Lima (regla de fechas), se muestra tal cual.
                    'fecha' => $p->fecha_pago?->format('Y-m-d H:i'),
                    'monto' => Dinero::aSoles(Dinero::aCentavos($p->monto_recibido)),
                    'recibo' => $p->numero_recibo,
                    'credito' => $p->credito?->numero_credito,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function vigente(DetalleCredito $d): array
    {
        $saldo = $d->saldo;
        $total = $d->credito->cuotasVigentes->count();
        $pagadas = $saldo?->cuotasPagadas ?? 0;
        $vencidas = array_values(array_filter($saldo?->deudaHoy ?? [], static fn ($x): bool => $x->vencida));
        $vencidoPendiente = array_sum(array_map(static fn ($x): int => $x->pendiente, $vencidas));
        $vencidoMora = array_sum(array_map(static fn ($x): int => $x->mora, $vencidas));
        $proxima = $saldo?->proxima;

        return [
            'numero' => $d->credito->numero_credito,
            'estado' => $vencidas !== [] ? 'atrasado' : 'al_dia',
            'monto_prestado' => Dinero::aSoles(Dinero::aCentavos($d->credito->monto_capital)),
            'cuotas_total' => $total,
            'cuotas_pagadas' => $pagadas,
            'cuotas_restantes' => max(0, $total - $pagadas),
            'saldo_por_pagar' => Dinero::aSoles($saldo?->porPagar() ?? 0),
            'proxima' => $proxima === null ? null : [
                'numero_cuota' => $proxima->numeroCuota,
                'fecha' => $proxima->fechaVencimiento->aTexto(),
                'monto' => Dinero::aSoles($proxima->pendiente),
            ],
            // Lo vencido incluye la mora que aún debe una cuota ya pagada; por eso el número de cuotas
            // sale del contador del motor (0 = solo debe mora).
            'vencido' => $vencidas === [] ? null : [
                'cuotas' => $saldo?->cuotasVencidas ?? 0,
                'dias_atraso' => $d->diasAtraso,
                'monto' => Dinero::aSoles($vencidoPendiente),
                'mora' => Dinero::aSoles($vencidoMora),
                'total' => Dinero::aSoles($vencidoPendiente + $vencidoMora),
            ],
        ];
    }
}
