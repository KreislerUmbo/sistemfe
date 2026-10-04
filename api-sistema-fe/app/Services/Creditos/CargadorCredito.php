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
use Illuminate\Support\Facades\DB;

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

    /**
     * @param list<int> $diasNoLaborables
     * @param list<Fecha>|null $feriados ya leídos (carga en bloque); null = se leen aquí
     */
    public function calendario(array $diasNoLaborables, bool $saltarFeriados, ReglaNoLaborable $regla, ?array $feriados = null): ReglasCalendario
    {
        $feriados = $saltarFeriados ? ($feriados ?? $this->feriados()) : [];

        return new ReglasCalendario(array_map('intval', $diasNoLaborables), $saltarFeriados, $feriados, $regla);
    }

    /** Estado vigente + pagos válidos para reaplicar (1.8). */
    public function cargar(Credito $credito): CreditoCargado
    {
        return $this->cargarVarios(collect([$credito]))[$credito->id];
    }

    /**
     * Varios créditos con una consulta por tabla (no una por crédito): la usan los reportes (04d).
     * Misma traducción que cargar(): un reporte y el detalle de un crédito suman igual.
     *
     * @param iterable<Credito> $creditos
     * @return array<int, CreditoCargado> credito_id => cargado
     */
    public function cargarVarios(iterable $creditos): array
    {
        $creditos = collect($creditos)->keyBy('id');
        if ($creditos->isEmpty()) {
            return [];
        }
        $ids = $creditos->keys()->all();

        // Cuotas de la versión vigente de cada crédito (la versión es por crédito).
        $cuotas = CreditoCuota::whereIn('credito_id', $ids)
            ->whereRaw('version_cronograma = (select c.version_cronograma_actual from creditos c where c.id = credito_cuotas.credito_id)')
            ->orderBy('numero_cuota')
            ->get()
            ->groupBy('credito_id');
        $cuotaIds = $cuotas->flatten()->pluck('id')->all();

        $condonadas = CreditoCondonacion::whereIn('cuota_id', $cuotaIds)
            ->where('estado', CondonacionEstado::Vigente)
            ->groupBy('cuota_id')
            ->selectRaw('cuota_id, sum(monto) as total')
            ->pluck('total', 'cuota_id');
        $congeladas = $this->morasCongeladas($cuotaIds);
        $castigos = CreditoCastigo::whereIn('credito_id', $ids)->orderBy('fecha_castigo')->get()->groupBy('credito_id');
        $pagos = CreditoPago::whereIn('credito_id', $ids)->where('estado', PagoEstado::Valido)->orderBy('id')->get()->groupBy('credito_id');
        $feriados = $creditos->contains(fn (Credito $c): bool => (bool) $c->saltar_feriados) ? $this->feriados() : [];

        return $creditos->map(fn (Credito $credito): CreditoCargado => $this->armar(
            $credito,
            $cuotas[$credito->id] ?? collect(),
            $condonadas,
            $congeladas,
            $castigos[$credito->id] ?? collect(),
            $pagos[$credito->id] ?? collect(),
            $feriados,
        ))->all();
    }

    /**
     * Traducción BD → motor de un crédito, con sus filas ya leídas.
     *
     * @param \Illuminate\Support\Collection<int, CreditoCuota> $cuotas
     * @param \Illuminate\Support\Collection<int|string, mixed> $condonadas cuota_id => total
     * @param array<int, list<MoraCongelada>> $congeladas
     * @param \Illuminate\Support\Collection<int, CreditoCastigo> $castigos
     * @param \Illuminate\Support\Collection<int, CreditoPago> $pagos válidos, por id
     * @param list<Fecha> $feriados
     */
    private function armar(Credito $credito, $cuotas, $condonadas, array $congeladas, $castigos, $pagos, array $feriados): CreditoCargado
    {
        $cuotas = $cuotas->sortBy('numero_cuota')->values();
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
                periodosCastigo: $this->periodosCastigo($castigos),
                pasoRedondeo: Dinero::aCentavos($credito->paso_redondeo),
                cobraMora: $credito->cobra_mora,
            ),
            $this->calendario($credito->dias_no_laborables, $credito->saltar_feriados, $credito->regla_no_laborable, $feriados),
            Dinero::aCentavos($credito->paso_redondeo),
        );

        $reaplicar = $pagos->sortBy('id')->map(fn (CreditoPago $p): PagoAAplicar => $this->paraReaplicar($p))->values()->all();

        return new CreditoCargado($estado, $reaplicar, $cuotas->keyBy('numero_cuota')->all());
    }

    /** @return list<Fecha> */
    private function feriados(): array
    {
        return Feriado::orderBy('fecha')->get()->map(fn (Feriado $f): Fecha => Fecha::desdeTexto($f->fecha->format('Y-m-d')))->all();
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

    /**
     * @param \Illuminate\Support\Collection<int, CreditoCastigo> $castigos
     * @return list<PeriodoCastigo>
     */
    private function periodosCastigo($castigos): array
    {
        return $castigos->sortBy('fecha_castigo')
            // Revertido el mismo día del castigo: período vacío, no suspende ningún día.
            ->reject(fn (CreditoCastigo $c): bool => $c->fecha_reversion !== null && ! $c->fecha_reversion->gt($c->fecha_castigo))
            ->map(fn (CreditoCastigo $c): PeriodoCastigo => new PeriodoCastigo(
                self::fecha($c->fecha_castigo),
                $c->fecha_reversion === null ? null : self::fecha($c->fecha_reversion),
            ))->values()->all();
    }
}
