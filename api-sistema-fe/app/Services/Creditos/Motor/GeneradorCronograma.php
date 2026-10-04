<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\CondicionesCredito;
use App\Services\Creditos\Motor\Dto\Cronograma;
use App\Services\Creditos\Motor\Dto\CuotaProgramada;
use App\Services\Creditos\Motor\Dto\CuotaVigente;
use App\Services\Creditos\Motor\Dto\FechaAjustada;
use App\Services\Creditos\Motor\Dto\Frecuencia;
use App\Services\Creditos\Motor\Dto\ReglasCalendario;
use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;
use App\Services\Creditos\Motor\Enums\MetodoCalculo;
use App\Services\Creditos\Motor\Enums\ModoRedondeo;
use App\Services\Creditos\Motor\Excepciones\CondicionesInvalidas;
use App\Services\Creditos\Motor\Excepciones\MetodoNoSoportado;

/** Genera y reprograma cronogramas (plan 1.1-1.4, 1.18, 12.1-12.3). Montos en centavos. */
final class GeneradorCronograma
{
    private const DIAS_SEMANA = 7;
    private const MESES_ANIO = 12;
    private const DIAS_POR_QUINCENA = 2;

    public function __construct(private readonly CalculadoraInteres $interes)
    {
    }

    public function generar(CondicionesCredito $c): Cronograma
    {
        $this->validar($c);

        $interesTotal = $this->interes->interesTotal(
            $c->montoCapital, $c->tasa, $c->unidadTasa, $c->frecuencia, $c->numeroCuotas, $c->pasoRedondeo,
        );
        $capitales = $this->dividirEnCuotas($c->montoCapital, $c->numeroCuotas, $c->pasoRedondeo);
        $intereses = $this->dividirEnCuotas($interesTotal, $c->numeroCuotas, $c->pasoRedondeo);
        $fechas = $this->fechasVencimiento(
            $c->frecuencia, $c->fechaDesembolso, $c->fechaPrimerVencimiento, $c->numeroCuotas, $c->calendario,
        );

        $cuotas = [];
        $inicio = $c->fechaDesembolso;
        foreach ($fechas as $i => $fecha) {
            $cuotas[] = new CuotaProgramada(
                $i + 1, $inicio, $fecha->fecha, $capitales[$i], $intereses[$i],
                $capitales[$i] + $intereses[$i], $fecha->forzadaASiguiente, $fecha->original,
            );
            $inicio = $fecha->fecha;
        }

        return new Cronograma($interesTotal, $c->montoCapital + $interesTotal, $cuotas);
    }

    /** Primer vencimiento por defecto: un intervalo después del desembolso, ya ajustado al calendario. */
    public function sugerirPrimerVencimiento(Fecha $desembolso, Frecuencia $frecuencia, ReglasCalendario $reglas): Fecha
    {
        $this->validarFrecuencia($frecuencia);

        return $this->fechasVencimiento($frecuencia, $desembolso, null, 1, $reglas)[0]->fecha;
    }

    /**
     * 1.18: desplaza $dias la cuota $desdeNumero y las siguientes, sin tocar montos ni el período
     * original (divisor de la mora).
     *
     * @param list<CuotaVigente> $cuotas
     * @param list<int> $numerosPagados cuotas ya pagadas, que no se pueden reprogramar
     * @return list<CuotaVigente>
     */
    public function desplazarFechas(array $cuotas, int $desdeNumero, int $dias, Fecha $hoy, array $numerosPagados = []): array
    {
        if ($dias === 0) {
            throw new CondicionesInvalidas('El desplazamiento debe ser distinto de cero.');
        }

        $fechasNuevas = [];
        foreach ($cuotas as $cuota) {
            if ($cuota->numero >= $desdeNumero) {
                $fechasNuevas[$cuota->numero] = $cuota->fechaVencimiento->sumarDias($dias);
            }
        }

        return $this->aplicarFechas($cuotas, $fechasNuevas, $hoy, $numerosPagados);
    }

    /**
     * 1.18: valida fechas nuevas (edición una por una): solo cuotas pendientes, no antes de hoy,
     * orden estrictamente ascendente respecto de todo el cronograma.
     *
     * @param list<CuotaVigente> $cuotas
     * @param array<int, Fecha> $fechasNuevas número de cuota => fecha nueva
     * @param list<int> $numerosPagados
     */
    public function validarFechasReprogramadas(array $cuotas, array $fechasNuevas, Fecha $hoy, array $numerosPagados = []): void
    {
        $numeros = array_map(static fn (CuotaVigente $c): int => $c->numero, $cuotas);

        foreach ($fechasNuevas as $numero => $fecha) {
            if (! in_array($numero, $numeros, true)) {
                throw new CondicionesInvalidas("La cuota {$numero} no existe.");
            }
            if (in_array($numero, $numerosPagados, true)) {
                throw new CondicionesInvalidas("La cuota {$numero} ya está pagada; solo se reprograman cuotas pendientes.");
            }
            if ($fecha->esAnteriorA($hoy)) {
                throw new CondicionesInvalidas("La nueva fecha de la cuota {$numero} es anterior a hoy.");
            }
        }

        $anterior = null;
        foreach ($cuotas as $cuota) {
            $fecha = $fechasNuevas[$cuota->numero] ?? $cuota->fechaVencimiento;
            if ($anterior !== null && ! $fecha->esPosteriorA($anterior)) {
                throw new CondicionesInvalidas("La cuota {$cuota->numero} quedaría en o antes de la cuota anterior.");
            }
            $anterior = $fecha;
        }
    }

    /**
     * @param list<CuotaVigente> $cuotas
     * @param array<int, Fecha> $fechasNuevas
     * @param list<int> $numerosPagados
     * @return list<CuotaVigente>
     */
    public function aplicarFechas(array $cuotas, array $fechasNuevas, Fecha $hoy, array $numerosPagados = []): array
    {
        $this->validarFechasReprogramadas($cuotas, $fechasNuevas, $hoy, $numerosPagados);

        return array_map(
            static fn (CuotaVigente $c): CuotaVigente => isset($fechasNuevas[$c->numero])
                ? $c->conFechaVencimiento($fechasNuevas[$c->numero])
                : $c,
            $cuotas,
        );
    }

    /**
     * 12.2: cada cuota = total ÷ n redondeado al paso; la última absorbe la diferencia. Si la
     * última quedara negativa (n grande), se redondea hacia abajo.
     *
     * @return list<int>
     */
    private function dividirEnCuotas(int $total, int $n, int $paso): array
    {
        $base = Redondeo::dividir($total, $n, $paso);
        if ($total - $base * ($n - 1) < 0) {
            $base = Redondeo::dividir($total, $n, $paso, ModoRedondeo::Abajo);
        }

        $montos = array_fill(0, $n, $base);
        $montos[$n - 1] = $total - $base * ($n - 1);

        return $montos;
    }

    /** @return list<FechaAjustada> */
    private function fechasVencimiento(
        Frecuencia $f,
        Fecha $desembolso,
        ?Fecha $primerVencimiento,
        int $n,
        ReglasCalendario $reglas,
    ): array {
        $calendario = new CalendarioLaborable($reglas);

        if ($f->unidad === FrecuenciaUnidad::Dia) {
            return $this->fechasDiarias($f->intervalo, $desembolso, $primerVencimiento, $n, $calendario);
        }

        $fechas = [];
        $limite = $desembolso;
        foreach ($this->fechasTeoricas($f, $desembolso, $primerVencimiento, $n) as $teorica) {
            $ajustada = $calendario->ajustarDetallado($teorica, $limite);
            // Con `siguiente`, dos fechas teóricas pegadas a días no laborables pueden caer en el mismo día.
            if (! $ajustada->fecha->esPosteriorA($limite)) {
                $ajustada = new FechaAjustada($calendario->siguienteLaborable($limite->sumarDias(1)), $ajustada->forzadaASiguiente);
            }
            $movida = $ajustada->fecha->aTexto() !== $teorica->aTexto();
            $fechas[] = new FechaAjustada($ajustada->fecha, $ajustada->forzadaASiguiente, $movida ? $teorica : null);
            $limite = $ajustada->fecha;
        }

        return $fechas;
    }

    /**
     * 1.4: en frecuencia diaria el día no laborable no genera cuota; se conserva el número de
     * cuotas y el cronograma se alarga.
     *
     * @return list<FechaAjustada>
     */
    private function fechasDiarias(int $intervalo, Fecha $desembolso, ?Fecha $primer, int $n, CalendarioLaborable $cal): array
    {
        // Solo el primer pago se explica como "movido": los siguientes días sin cobro simplemente
        // no generan cuota (lo dice el checkbox), y anotarlos llenaría el cronograma de avisos.
        $teorica = $primer ?? $desembolso->sumarDias($intervalo);
        $fecha = $cal->siguienteLaborable($teorica);
        $fechas = [new FechaAjustada($fecha, false, $fecha->aTexto() !== $teorica->aTexto() ? $teorica : null)];
        while (count($fechas) < $n) {
            $fecha = $cal->siguienteLaborable($fecha->sumarDias($intervalo));
            $fechas[] = new FechaAjustada($fecha);
        }

        return $fechas;
    }

    /**
     * Fechas antes de aplicar días no laborables, ancladas a la primera para que un ajuste no
     * arrastre a las siguientes (1.3: 31/01 → 28/02 → 31/03).
     *
     * @return list<Fecha>
     */
    private function fechasTeoricas(Frecuencia $f, Fecha $desembolso, ?Fecha $primer, int $n): array
    {
        if ($f->unidad === FrecuenciaUnidad::Quincena) {
            return $this->fechasQuincenales($f, $desembolso, $primer, $n);
        }

        // Sin primer vencimiento explícito se ancla al desembolso, desplazado un intervalo.
        $base = $primer ?? $desembolso;
        $desfase = $primer === null ? 1 : 0;
        $fechas = [];
        for ($k = 0; $k < $n; $k++) {
            $pasos = ($k + $desfase) * $f->intervalo;
            $fechas[] = match ($f->unidad) {
                FrecuenciaUnidad::Semana => $base->sumarDias($pasos * self::DIAS_SEMANA),
                FrecuenciaUnidad::Mes => $base->sumarMesesAnclado($pasos, $base->dia),
                FrecuenciaUnidad::Anio => $base->sumarMesesAnclado($pasos * self::MESES_ANIO, $base->dia),
            };
        }

        return $fechas;
    }

    /**
     * 1.3: quincenal por días fijos del mes, p.ej. [15, 'ultimo'].
     *
     * @return list<Fecha>
     */
    private function fechasQuincenales(Frecuencia $f, Fecha $desembolso, ?Fecha $primer, int $n): array
    {
        $fecha = $primer;
        if ($fecha === null) {
            $fecha = $desembolso;
            for ($i = 0; $i < $f->intervalo; $i++) {
                $fecha = $this->siguientePuntoQuincenal($fecha, $f->diasQuincena);
            }
        }

        $fechas = [$fecha];
        while (count($fechas) < $n) {
            for ($i = 0; $i < $f->intervalo; $i++) {
                $fecha = $this->siguientePuntoQuincenal($fecha, $f->diasQuincena);
            }
            $fechas[] = $fecha;
        }

        return $fechas;
    }

    /** @param list<int|string> $dias */
    private function siguientePuntoQuincenal(Fecha $despuesDe, array $dias): Fecha
    {
        $mes = $despuesDe->conDia(1);
        while (true) {
            $puntos = array_map(
                static fn (int|string $d): Fecha => $d === Frecuencia::ULTIMO_DIA ? $mes->ultimoDiaDelMes() : $mes->conDia($d),
                $dias,
            );
            usort($puntos, static fn (Fecha $a, Fecha $b): int => $a->comparar($b));
            foreach ($puntos as $punto) {
                if ($punto->esPosteriorA($despuesDe)) {
                    return $punto;
                }
            }
            $mes = $mes->sumarMesesAnclado(1, 1);
        }
    }

    private function validar(CondicionesCredito $c): void
    {
        if ($c->metodo !== MetodoCalculo::SimpleFijo) {
            throw new MetodoNoSoportado("Método {$c->metodo->value} no implementado.");
        }
        if ($c->montoCapital <= 0) {
            throw new CondicionesInvalidas('El capital debe ser mayor a cero.');
        }
        if ($c->pasoRedondeo <= 0 || $c->montoCapital % $c->pasoRedondeo !== 0) {
            // 12.1: con capital e interés múltiplos del paso, toda cuota lo es.
            throw new CondicionesInvalidas('El capital debe ser múltiplo del paso de redondeo.');
        }
        if ($c->numeroCuotas < 1) {
            throw new CondicionesInvalidas('Debe haber al menos una cuota.');
        }
        if ($c->fechaPrimerVencimiento !== null && ! $c->fechaPrimerVencimiento->esPosteriorA($c->fechaDesembolso)) {
            throw new CondicionesInvalidas('El primer vencimiento debe ser posterior al desembolso.');
        }
        $this->validarFrecuencia($c->frecuencia);
    }

    private function validarFrecuencia(Frecuencia $f): void
    {
        if ($f->intervalo < 1) {
            throw new CondicionesInvalidas('El intervalo de la frecuencia debe ser al menos 1.');
        }
        if ($f->unidad !== FrecuenciaUnidad::Quincena) {
            return;
        }

        $dias = $f->diasQuincena ?? [];
        $validos = array_filter(
            $dias,
            static fn (mixed $d): bool => $d === Frecuencia::ULTIMO_DIA || (is_int($d) && $d >= 1 && $d <= 31),
        );
        if (count($dias) !== self::DIAS_POR_QUINCENA || count($validos) !== self::DIAS_POR_QUINCENA || $dias[0] === $dias[1]) {
            throw new CondicionesInvalidas("La frecuencia quincenal requiere dos días distintos del mes (1-31 o 'ultimo').");
        }
    }
}
