<?php

declare(strict_types=1);

namespace App\Http\Resources\Creditos;

use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\CambioFecha;
use App\Services\Creditos\Dto\CotizacionPago;
use App\Services\Creditos\Dto\DeudaCuota;
use App\Services\Creditos\Dto\ProximaCuota;
use App\Services\Creditos\Dto\SaldoCredito;
use App\Services\Creditos\Dto\PreviewReprogramacion;
use App\Services\Creditos\Dto\PreviewRenovacion;
use App\Services\Creditos\Motor\Dto\Aplicacion;
use App\Services\Creditos\Motor\Dto\Cronograma;
use App\Services\Creditos\Motor\Dto\CuotaProgramada;
use App\Services\Creditos\Motor\Dto\Infraccion;
use App\Services\Creditos\Motor\Dto\Liquidacion;
use App\Services\Creditos\Motor\Dto\RepartoLiquidacionCuota;
use App\Services\Creditos\Motor\Dto\ResultadoLimites;

/** DTOs del motor → JSON (montos en soles con 2 decimales). */
final class FormatoCredito
{
    /** @return array<string, mixed> */
    public static function cronograma(Cronograma $c): array
    {
        $cuotas = $c->cuotas;

        return [
            'interes_total' => Dinero::aSoles($c->interesTotal),
            'monto_total' => Dinero::aSoles($c->montoTotal),
            'primer_vencimiento' => $cuotas[0]->fechaVencimiento->aTexto(),
            'ultimo_vencimiento' => $cuotas[array_key_last($cuotas)]->fechaVencimiento->aTexto(),
            'cuotas' => array_map(static fn (CuotaProgramada $q): array => [
                'numero_cuota' => $q->numero,
                'fecha_vencimiento' => $q->fechaVencimiento->aTexto(),
                'monto_capital' => Dinero::aSoles($q->montoCapital),
                'monto_interes' => Dinero::aSoles($q->montoInteres),
                'monto_total' => Dinero::aSoles($q->montoTotal),
                'fecha_forzada_a_siguiente' => $q->fechaForzadaASiguiente,
            ], $cuotas),
        ];
    }

    /** @return array<string, mixed> */
    public static function limites(ResultadoLimites $r): array
    {
        $formato = static fn (Infraccion $i): array => ['regla' => $i->regla->value, 'autorizable' => $i->autorizable, 'detalle' => $i->detalle];

        return [
            'bloquea' => $r->bloquea(),
            'bloqueos' => array_map($formato, $r->bloqueos()),
            'advertencias' => array_map($formato, $r->advertencias()),
        ];
    }

    /** @return array<string, mixed> */
    public static function liquidacion(Liquidacion $l): array
    {
        return [
            'fecha' => $l->fechaReferencia->aTexto(),
            'capital_pendiente' => Dinero::aSoles($l->capitalPendiente),
            'interes_devengado' => Dinero::aSoles($l->interesDevengado),
            'interes_minimo' => Dinero::aSoles($l->interesMinimo),
            'interes_final' => Dinero::aSoles($l->interesFinal),
            'interes_cobrado' => Dinero::aSoles($l->interesCobrado),
            'interes_a_cobrar' => Dinero::aSoles($l->interesACobrar),
            'interes_descontado' => Dinero::aSoles($l->interesCondonado),
            'cargos_pendientes' => Dinero::aSoles($l->cargoPendiente),
            'mora_pendiente' => Dinero::aSoles($l->moraPendiente),
            'monto_liquidacion' => Dinero::aSoles($l->montoLiquidacion),
            'reparto' => array_map(static fn (RepartoLiquidacionCuota $r): array => [
                'numero_cuota' => $r->numeroCuota,
                'capital' => Dinero::aSoles($r->capital),
                'interes' => Dinero::aSoles($r->interes),
                'interes_descontado' => Dinero::aSoles($r->interesCondonado),
                'cargo' => Dinero::aSoles($r->cargo),
                'mora' => Dinero::aSoles($r->mora),
            ], $l->reparto),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function proxima(?ProximaCuota $p): ?array
    {
        return $p === null ? null : [
            'numero_cuota' => $p->numeroCuota,
            'fecha_vencimiento' => $p->fechaVencimiento->aTexto(),
            'pendiente' => Dinero::aSoles($p->pendiente),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function deudaHoy(SaldoCredito $s): array
    {
        return array_map(static fn (DeudaCuota $d): array => [
            'numero_cuota' => $d->numeroCuota,
            'fecha_vencimiento' => $d->fechaVencimiento->aTexto(),
            'vencida' => $d->vencida,
            'pendiente' => Dinero::aSoles($d->pendiente),
            'mora' => Dinero::aSoles($d->mora),
            'dias_atraso' => $d->diasAtraso,
            'mora_tope_alcanzado' => $d->moraTopeAlcanzado,
        ], $s->deudaHoy);
    }

    /** @return array<string, mixed> */
    public static function cotizacion(CotizacionPago $c): array
    {
        return [
            'exigible_hoy' => Dinero::aSoles($c->exigible),
            'monto_aplicado' => Dinero::aSoles($c->montoAplicado),
            'monto_excedente' => Dinero::aSoles($c->montoExcedente),
            'finaliza_credito' => $c->finalizaCredito,
            'saldo_despues' => Dinero::aSoles($c->despues->porPagar()),
            'proxima_despues' => self::proxima($c->despues->proxima),
            'aplicacion' => array_map(static fn (Aplicacion $a): array => [
                'numero_cuota' => $a->numeroCuota,
                'concepto' => $a->concepto->value,
                'monto' => Dinero::aSoles($a->monto),
            ], $c->lineas),
        ];
    }

    /** @return array<string, mixed> */
    public static function reprogramacion(PreviewReprogramacion $p): array
    {
        return [
            'cambios' => array_map(static fn (CambioFecha $c): array => [
                'numero_cuota' => $c->numeroCuota,
                'fecha_anterior' => $c->fechaAnterior->aTexto(),
                'fecha_nueva' => $c->fechaNueva->aTexto(),
                'mora_congelada' => Dinero::aSoles($c->moraCongelada),
                'cae_en_no_laborable' => $c->caeEnNoLaborable,
            ], $p->cambios),
            'mora_acumulada' => Dinero::aSoles($p->moraAcumulada),
            'accion_mora' => $p->accionMora->value,
            'cargo_tipo' => $p->cargoTipo->value,
            'cargo_sugerido' => Dinero::aSoles($p->cargoSugerido),
            'cargo' => Dinero::aSoles($p->cargo),
        ];
    }

    /** @return array<string, mixed> */
    public static function renovacion(PreviewRenovacion $p): array
    {
        return [
            'liquidacion' => self::liquidacion($p->liquidacion),
            'capital_nuevo' => Dinero::aSoles($p->capitalNuevo),
            'entrega_neta' => Dinero::aSoles($p->entregaNeta),
            'cronograma' => self::cronograma($p->cronograma),
            'limites' => self::limites($p->limites),
        ];
    }
}
