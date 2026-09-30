<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CondonacionEstado;
use App\Enums\Creditos\PagoEstado;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoCastigo;
use App\Models\Creditos\CreditoCondonacion;
use App\Models\Creditos\CreditoCuota;
use App\Models\Creditos\CreditoPago;
use App\Models\Creditos\CreditoReprogramacionCuota;
use App\Models\Creditos\Feriado;
use App\Services\Creditos\Dto\CreditoCargado;
use App\Services\Creditos\Dto\DatosCredito;
use App\Services\Creditos\Motor\Dto\CondicionesCredito;
use App\Services\Creditos\Motor\Dto\CuotaVigente;
use App\Services\Creditos\Motor\Dto\EstadoCredito;
use App\Services\Creditos\Motor\Dto\Frecuencia;
use App\Services\Creditos\Motor\Dto\MoraCongelada;
use App\Services\Creditos\Motor\Dto\PagoAAplicar;
use App\Services\Creditos\Motor\Dto\PeriodoCastigo;
use App\Services\Creditos\Motor\Dto\ReglasCalendario;
use App\Services\Creditos\Motor\Dto\ReglasMora;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Motor\Tasa;

/**
 * Arma los DTOs del motor desde Eloquent (03-api "cargador"). Es la única traducción
 * BD → motor: cobro, anulación, retroactivo, liquidación y reprogramación la comparten.
 */
class CargadorCredito
{
    public function condiciones(DatosCredito $d): CondicionesCredito
    {
        return new CondicionesCredito(
            montoCapital: $d->montoCapital,
            tasa: $d->tasa,
            unidadTasa: $d->unidadTasa,
            frecuencia: $d->frecuencia,
            numeroCuotas: $d->numeroCuotas,
            fechaDesembolso: $d->fechaDesembolso,
            fechaPrimerVencimiento: $d->fechaPrimerVencimiento,
            calendario: $this->calendario($d->diasNoLaborables, $d->saltarFeriados, $d->reglaNoLaborable),
            pasoRedondeo: $d->pasoRedondeo,
        );
    }

    /** @param list<int> $diasNoLaborables */
    public function calendario(array $diasNoLaborables, bool $saltarFeriados, ReglaNoLaborable $regla): ReglasCalendario
    {
        $feriados = $saltarFeriados
            ? Feriado::orderBy('fecha')->get()->map(fn (Feriado $f): Fecha => Fecha::desdeTexto($f->fecha->format('Y-m-d')))->all()
            : [];

        return new ReglasCalendario(array_map('intval', $diasNoLaborables), $saltarFeriados, $feriados, $regla);
    }

    /** Estado vigente + pagos válidos para reaplicar (1.8). */
    public function cargar(Credito $credito): CreditoCargado
    {
        $cuotas = $credito->cuotasVigentes()->get();
        $condonadas = CreditoCondonacion::whereIn('cuota_id', $cuotas->pluck('id'))
            ->where('estado', CondonacionEstado::Vigente)
            ->groupBy('cuota_id')
            ->selectRaw('cuota_id, sum(monto) as total')
            ->pluck('total', 'cuota_id');
        $congeladas = $this->morasCongeladas($cuotas->pluck('id')->all());

        $vigentes = $cuotas->map(fn (CreditoCuota $c): CuotaVigente => new CuotaVigente(
            $c->numero_cuota,
            self::fecha($c->fecha_inicio_periodo),
            self::fecha($c->fecha_vencimiento),
            self::fecha($c->fecha_vencimiento_original),
            Dinero::aCentavos($c->monto_capital),
            Dinero::aCentavos($c->monto_interes),
            Dinero::aCentavos($c->cargo_monto),
            0,   // la congelada va fechada (morasCongeladas); credito_cuotas.mora_congelada es solo el total
            Dinero::aCentavos((string) ($condonadas[$c->id] ?? '0')),
            $congeladas[$c->id] ?? [],
        ))->values()->all();

        $estado = new EstadoCredito(
            Dinero::aCentavos($credito->monto_capital),
            Dinero::aCentavos($credito->interes_total),
            Tasa::desdeTexto($credito->tasa_interes_minimo),
            $vigentes,
            new ReglasMora(
                diasGracia: $credito->dias_gracia,
                cuentaNoLaborables: $credito->mora_cuenta_no_laborables,
                topeTipo: $credito->tope_mora_tipo,
                topeValor: $credito->tope_mora_valor,
                periodosCastigo: $this->periodosCastigo($credito),
                pasoRedondeo: Dinero::aCentavos($credito->paso_redondeo),
            ),
            $this->calendario($credito->dias_no_laborables, $credito->saltar_feriados, $credito->regla_no_laborable),
            Dinero::aCentavos($credito->paso_redondeo),
        );

        $pagos = $credito->pagos()->where('estado', PagoEstado::Valido)->orderBy('id')->get()
            ->map(fn (CreditoPago $p): PagoAAplicar => $this->paraReaplicar($p))
            ->values()->all();

        return new CreditoCargado($estado, $pagos, $cuotas->keyBy('numero_cuota')->all());
    }

    public static function frecuencia(Credito $credito): Frecuencia
    {
        return new Frecuencia($credito->frecuencia_unidad, $credito->frecuencia_intervalo, $credito->dias_quincena);
    }

    public static function fecha(\DateTimeInterface $fecha): Fecha
    {
        return Fecha::desdeTexto($fecha->format('Y-m-d'));
    }

    /**
     * Un pago ya registrado se reaplica por lo que el crédito CONSERVÓ (monto_aplicado) y con
     * destino adelanto: lo devuelto en caja o pasado a saldo a favor ya salió del crédito y no
     * vuelve a entrar al recalcular.
     */
    private function paraReaplicar(CreditoPago $pago): PagoAAplicar
    {
        return new PagoAAplicar(
            $pago->id,
            self::fecha($pago->fecha_pago),
            $pago->id,
            Dinero::aCentavos($pago->monto_aplicado),
            $pago->origen,
            DestinoExcedente::Adelanto,
            $pago->origen === OrigenPago::VentaPrenda ? $pago->es_cierre : null,
        );
    }

    /**
     * Mora congelada por reprogramación, fechada en el día (Lima) de cada reprogramación (00 1.9).
     *
     * @param list<int> $cuotaIds
     * @return array<int, list<MoraCongelada>> cuota_id => congeladas
     */
    private function morasCongeladas(array $cuotaIds): array
    {
        $porCuota = [];
        CreditoReprogramacionCuota::with('reprogramacion')
            ->whereIn('cuota_id', $cuotaIds)
            ->where('mora_congelada', '>', 0)
            ->get()
            ->each(function (CreditoReprogramacionCuota $r) use (&$porCuota): void {
                $dia = $r->reprogramacion->created_at->copy()->setTimezone(Reloj::ZONA)->format('Y-m-d');
                $porCuota[$r->cuota_id][] = new MoraCongelada(Fecha::desdeTexto($dia), Dinero::aCentavos($r->mora_congelada));
            });

        return $porCuota;
    }

    /** @return list<PeriodoCastigo> */
    private function periodosCastigo(Credito $credito): array
    {
        return $credito->castigos()->orderBy('fecha_castigo')->get()
            // Revertido el mismo día del castigo: período vacío, no suspende ningún día.
            ->reject(fn (CreditoCastigo $c): bool => $c->fecha_reversion !== null && ! $c->fecha_reversion->gt($c->fecha_castigo))
            ->map(fn (CreditoCastigo $c): PeriodoCastigo => new PeriodoCastigo(
                self::fecha($c->fecha_castigo),
                $c->fecha_reversion === null ? null : self::fecha($c->fecha_reversion),
            ))->values()->all();
    }
}
