<?php

declare(strict_types=1);

namespace Tests\Unit\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\Frecuencia;
use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;
use App\Services\Creditos\Motor\Enums\TipoCargoReprogramacion;
use App\Services\Creditos\Motor\Enums\UnidadTasa;
use App\Services\Creditos\Motor\Redondeo;
use App\Services\Creditos\Motor\Tasa;
use PHPUnit\Framework\TestCase;

class CalculadoraInteresTest extends TestCase
{
    use ConstruyeCreditos;

    private const PASO = Redondeo::PASO_DIEZ_CENTIMOS;

    public function test_tasa_total_ignora_el_plazo(): void
    {
        // "1,000 al 20%" = 200 sin importar el plazo (1.1).
        $interes = $this->interes()->interesTotal(
            100_000, Tasa::desdeTexto('20'), UnidadTasa::Total, new Frecuencia(FrecuenciaUnidad::Semana), 52,
        );

        $this->assertSame(20_000, $interes);
    }

    public function test_tasa_mensual_con_cuotas_mensuales(): void
    {
        $interes = $this->interes()->interesTotal(
            100_000, Tasa::desdeTexto('5'), UnidadTasa::Mensual, new Frecuencia(FrecuenciaUnidad::Mes), 12,
        );

        $this->assertSame(60_000, $interes);
    }

    public function test_tasa_mensual_con_cuotas_semanales_usa_mes_comercial(): void
    {
        // 12.1: 1,000 × 10% × 10 cuotas × 7/30 = 233.333… → 233.30
        $interes = $this->interes()->interesTotal(
            100_000, Tasa::desdeTexto('10'), UnidadTasa::Mensual, new Frecuencia(FrecuenciaUnidad::Semana), 10,
        );

        $this->assertSame(23_330, $interes);
    }

    public function test_tasa_mensual_quincenal_por_ambas_reglas_es_medio_mes(): void
    {
        $porQuincena = $this->interes()->interesTotal(
            100_000, Tasa::desdeTexto('10'), UnidadTasa::Mensual,
            new Frecuencia(FrecuenciaUnidad::Quincena, 1, [15, Frecuencia::ULTIMO_DIA]), 4,
        );
        $corrido = $this->interes()->interesTotal(
            100_000, Tasa::desdeTexto('10'), UnidadTasa::Mensual, new Frecuencia(FrecuenciaUnidad::Dia, 15), 4,
        );

        $this->assertSame(20_000, $porQuincena);
        $this->assertSame(20_000, $corrido);
    }

    public function test_tasa_mensual_diaria_y_anual(): void
    {
        $diaria = $this->interes()->interesTotal(
            100_000, Tasa::desdeTexto('10'), UnidadTasa::Mensual, new Frecuencia(FrecuenciaUnidad::Dia), 24,
        );
        $anual = $this->interes()->interesTotal(
            100_000, Tasa::desdeTexto('1'), UnidadTasa::Mensual, new Frecuencia(FrecuenciaUnidad::Anio), 5,
        );

        $this->assertSame(8_000, $diaria);    // 1,000 × 10% × 24/30 = 80
        $this->assertSame(60_000, $anual);    // 1,000 × 1% × 60 meses = 600
    }

    public function test_interes_se_redondea_una_sola_vez_al_paso(): void
    {
        // 1,000 × 3.3333% = 33.333 → 33.30 (12.1)
        $interes = $this->interes()->interesTotal(
            100_000, Tasa::desdeTexto('3.3333'), UnidadTasa::Total, new Frecuencia(FrecuenciaUnidad::Mes), 3,
        );

        $this->assertSame(3_330, $interes);
    }

    public function test_proporcional(): void
    {
        $this->assertSame(5_000, $this->interes()->proporcional(10_000, 15, 30, self::PASO));
        $this->assertSame(0, $this->interes()->proporcional(10_000, 0, 30, self::PASO));
        $this->assertSame(10_000, $this->interes()->proporcional(10_000, 31, 30, self::PASO));
    }

    public function test_cargo_por_reprogramacion_fijo_ninguno_e_interes_por_dias(): void
    {
        $i = $this->interes();

        $this->assertSame(0, $i->cargoReprogramacion(TipoCargoReprogramacion::Ninguno, 2_000, 100_000, 300, 10, self::PASO));
        $this->assertSame(2_000, $i->cargoReprogramacion(TipoCargoReprogramacion::Fijo, 2_000, 100_000, 300, 10, self::PASO));
        // 1,000 de interés ÷ 300 días × 10 días = 33.33 → 33.30
        $this->assertSame(3_330, $i->cargoReprogramacion(TipoCargoReprogramacion::InteresPorDias, 0, 100_000, 300, 10, self::PASO));
    }
}
