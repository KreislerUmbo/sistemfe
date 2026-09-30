<?php

declare(strict_types=1);

namespace Tests\Unit\Creditos\Motor;

use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\CalculadoraInteres;
use App\Services\Creditos\Motor\CalculadoraLiquidacion;
use App\Services\Creditos\Motor\CalculadoraMora;
use App\Services\Creditos\Motor\Dto\CondicionesCredito;
use App\Services\Creditos\Motor\Dto\Cronograma;
use App\Services\Creditos\Motor\Dto\CuotaVigente;
use App\Services\Creditos\Motor\Dto\EstadoCredito;
use App\Services\Creditos\Motor\Dto\Frecuencia;
use App\Services\Creditos\Motor\Dto\PagoAAplicar;
use App\Services\Creditos\Motor\Dto\ReglasCalendario;
use App\Services\Creditos\Motor\Dto\ReglasMora;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Enums\UnidadTasa;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Motor\GeneradorCronograma;
use App\Services\Creditos\Motor\Tasa;

/** Escenarios de prueba del motor. Todos los montos en centavos (S/ 1.00 = 100). */
trait ConstruyeCreditos
{
    private int $secuenciaPago = 0;

    protected static function f(string $ymd): Fecha
    {
        return Fecha::desdeTexto($ymd);
    }

    protected function interes(): CalculadoraInteres
    {
        return new CalculadoraInteres();
    }

    protected function generador(): GeneradorCronograma
    {
        return new GeneradorCronograma($this->interes());
    }

    protected function mora(): CalculadoraMora
    {
        return new CalculadoraMora($this->interes());
    }

    protected function liquidador(): CalculadoraLiquidacion
    {
        return new CalculadoraLiquidacion($this->interes());
    }

    protected function aplicador(): AplicadorPagos
    {
        return new AplicadorPagos($this->mora(), $this->liquidador());
    }

    /** Todos los días laborables: aísla los cálculos de dinero del calendario. */
    protected static function sinNoLaborables(): ReglasCalendario
    {
        return new ReglasCalendario(diasNoLaborables: []);
    }

    /** Cuotas cada 30 días exactos (frecuencia diaria de intervalo 30 sin días no laborables). */
    protected function cronogramaCada30Dias(int $capital, string $tasaTotal, int $cuotas, string $desembolso): Cronograma
    {
        return $this->generador()->generar(new CondicionesCredito(
            montoCapital: $capital,
            tasa: Tasa::desdeTexto($tasaTotal),
            unidadTasa: UnidadTasa::Total,
            frecuencia: new Frecuencia(FrecuenciaUnidad::Dia, 30),
            numeroCuotas: $cuotas,
            fechaDesembolso: self::f($desembolso),
            calendario: self::sinNoLaborables(),
        ));
    }

    protected function estado(
        Cronograma $cronograma,
        int $capital,
        string $tasaMinimo = '10',
        ReglasMora $reglasMora = new ReglasMora(),
    ): EstadoCredito {
        return new EstadoCredito(
            $capital,
            $cronograma->interesTotal,
            Tasa::desdeTexto($tasaMinimo),
            array_map(CuotaVigente::desdeProgramada(...), $cronograma->cuotas),
            $reglasMora,
            self::sinNoLaborables(),
        );
    }

    /**
     * Ejemplo de referencia del plan 1.5: 5,000 al 20% total, 10 cuotas de 600 (500 + 100) cada
     * 30 días desde el 01/01/2026. Vencimientos: c1 31/01, c2 02/03, c3 01/04, c4 01/05, c5 31/05…
     */
    protected function creditoEjemplo15(string $tasaMinimo = '10', ReglasMora $reglasMora = new ReglasMora()): EstadoCredito
    {
        return $this->estado($this->cronogramaCada30Dias(500_000, '20', 10, '2026-01-01'), 500_000, $tasaMinimo, $reglasMora);
    }

    /** Ejemplo de referencia del plan 1.6: 1,000 al 20%, 1 cuota de 1,200, período 10/09 → 10/10 (30 días). */
    protected function creditoEjemplo16(ReglasMora $reglasMora = new ReglasMora()): EstadoCredito
    {
        return $this->estado($this->cronogramaCada30Dias(100_000, '20', 1, '2026-09-10'), 100_000, '10', $reglasMora);
    }

    protected function pago(
        string $referencia,
        string $fecha,
        int $monto,
        OrigenPago $origen = OrigenPago::Cobro,
        DestinoExcedente $destino = DestinoExcedente::Devolver,
        ?bool $esCierre = null,
    ): PagoAAplicar {
        return new PagoAAplicar($referencia, self::f($fecha), ++$this->secuenciaPago, $monto, $origen, $destino, $esCierre);
    }
}
