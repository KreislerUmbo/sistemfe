<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Http\Resources\Creditos\FormatoCredito;
use App\Models\Creditos\Feriado;
use App\Services\Creditos\CreditoBorradorService;
use App\Services\Creditos\Dto\DatosCredito;
use App\Services\Creditos\Motor\Dto\Frecuencia;
use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Enums\UnidadTasa;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Motor\Tasa;

/** Revisión UX del formulario: por qué se movió una fecha, cuotas en feriado y tasa mensual simple. */
class PreviewCronogramaTest extends CreditosTestCase
{
    /** @param list<int> $noLaborables */
    private function preview(Frecuencia $frecuencia, int $cuotas, string $desembolso, array $noLaborables, bool $saltarFeriados, ReglaNoLaborable $regla = ReglaNoLaborable::Siguiente): array
    {
        $datos = new DatosCredito(
            0, 100_000, Tasa::desdeTexto('20'), UnidadTasa::Total, $frecuencia, $cuotas, Fecha::desdeTexto($desembolso), null,
            $noLaborables, $saltarFeriados, $regla, true, Tasa::desdeTexto('10'), 0, TopeMoraTipo::SinTope, null, 10, null,
        );

        return FormatoCredito::cronograma(app(CreditoBorradorService::class)->preview($datos));
    }

    public function test_explica_la_cuota_movida_por_domingo_y_por_feriado(): void
    {
        Feriado::create(['fecha' => '2026-01-19', 'descripcion' => 'Feriado de prueba', 'origen' => 'propio']);

        // Semanal desde el domingo 04/01: 11/01 (domingo) y 18/01 (domingo) se mueven al lunes;
        // el lunes 19/01 es feriado, así que la 2.ª pasa al martes 20/01.
        $cuotas = $this->preview(new Frecuencia(FrecuenciaUnidad::Semana, 1), 3, '2026-01-04', [7], true)['cuotas'];

        $this->assertSame(['2026-01-12', '2026-01-20', '2026-01-26'], array_column($cuotas, 'fecha_vencimiento'));
        $this->assertSame(['2026-01-11', '2026-01-18', '2026-01-25'], array_column($cuotas, 'fecha_original'));
        $this->assertSame(['domingo', 'domingo', 'domingo'], array_column($cuotas, 'motivo_ajuste'));
    }

    public function test_sin_saltar_feriados_avisa_la_cuota_que_cae_en_feriado(): void
    {
        Feriado::create(['fecha' => '2026-01-14', 'descripcion' => 'Aniversario', 'origen' => 'propio']);

        $cuotas = $this->preview(new Frecuencia(FrecuenciaUnidad::Semana, 1), 2, '2026-01-07', [], false)['cuotas'];

        $this->assertSame('2026-01-14', $cuotas[0]['fecha_vencimiento']);
        $this->assertSame('Aniversario', $cuotas[0]['feriado']);
        $this->assertNull($cuotas[0]['fecha_original']);
        $this->assertNull($cuotas[1]['feriado']);
    }

    public function test_en_frecuencia_diaria_solo_se_explica_el_primer_pago(): void
    {
        // Desembolso sábado 03/01: el primer pago (domingo 04/01) pasa al lunes; los demás domingos
        // simplemente no generan cuota.
        $cuotas = $this->preview(new Frecuencia(FrecuenciaUnidad::Dia, 1), 10, '2026-01-03', [7], false)['cuotas'];

        $this->assertSame('2026-01-04', $cuotas[0]['fecha_original']);
        $this->assertSame('domingo', $cuotas[0]['motivo_ajuste']);
        $this->assertSame([], array_filter(array_slice(array_column($cuotas, 'fecha_original'), 1)));
    }

    public function test_tasa_mensual_simple_equivalente(): void
    {
        // 20% sobre el total en 60 días (2 cuotas cada 30 días) ≈ 10% mensual.
        $preview = $this->preview(new Frecuencia(FrecuenciaUnidad::Dia, 30), 2, '2026-01-01', [], false);

        $this->assertSame('10.00', $preview['tasa_mensual_equivalente']);
    }
}
