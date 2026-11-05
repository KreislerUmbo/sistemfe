<?php

namespace Tests\Unit\AgenciaViajes;

use App\Services\AgenciaViajes\CriterioTributarioService;
use PHPUnit\Framework\TestCase;

// T-085 — el criterio extraído de ReservaFacturacionController. Los casos de
// integración (con reservas reales) siguen en ReservaFacturacionTest.
class CriterioTributarioServiceTest extends TestCase
{
    private function linea($id, string $destino, ?string $carga = null, bool $confirmado = false): array
    {
        return ['id' => $id, 'destino' => $destino, 'carga_tour' => $carga, 'confirmado' => $confirmado];
    }

    private function evaluar(array $lineas): ?array
    {
        return (new CriterioTributarioService)->evaluar(collect($lineas));
    }

    public function test_un_solo_destino_nacional_no_bloquea(): void
    {
        $this->assertNull($this->evaluar([$this->linea(1, 'nacional'), $this->linea(2, 'nacional')]));
    }

    public function test_mezcla_de_destinos_sueltos_bloquea(): void
    {
        $r = $this->evaluar([$this->linea(1, 'amazonia', null, true), $this->linea(2, 'nacional')]);

        $this->assertSame(CriterioTributarioService::MENSAJE_MEZCLA_TRIBUTARIA, $r['motivo']);
    }

    public function test_mezcla_dentro_de_una_sola_carga_de_tour_se_permite(): void
    {
        $r = $this->evaluar([
            $this->linea(1, 'amazonia', 'T1', true),
            $this->linea(2, 'nacional', 'T1'),
        ]);

        $this->assertNull($r);
    }

    public function test_dos_cargas_mezcladas_bloquean(): void
    {
        $r = $this->evaluar([
            $this->linea(1, 'amazonia', 'T1', true), $this->linea(2, 'nacional', 'T1'),
            $this->linea(3, 'amazonia', 'T2', true), $this->linea(4, 'nacional', 'T2'),
        ]);

        $this->assertSame(CriterioTributarioService::MENSAJE_MEZCLA_TRIBUTARIA, $r['motivo']);
    }

    public function test_extranjero_solo_da_su_mensaje_y_mezclado_da_el_de_mezcla(): void
    {
        $solo = $this->evaluar([$this->linea(1, 'extranjero')]);
        $mezclado = $this->evaluar([$this->linea(1, 'extranjero'), $this->linea(2, 'nacional')]);

        $this->assertSame(CriterioTributarioService::MENSAJE_TRATAMIENTO_TRIBUTARIO_EXTRANJERO, $solo['motivo']);
        $this->assertSame(CriterioTributarioService::MENSAJE_MEZCLA_TRIBUTARIA, $mezclado['motivo']);
    }

    public function test_amazonia_sin_confirmar_lista_los_ids_y_confirmada_no_bloquea(): void
    {
        $sin = $this->evaluar([$this->linea(7, 'amazonia'), $this->linea(8, 'amazonia', null, true)]);
        $this->assertSame(CriterioTributarioService::MENSAJE_AMAZONIA_SIN_CONFIRMAR, $sin['motivo']);
        $this->assertSame([7], $sin['sin_confirmar_ids']);

        $this->assertNull($this->evaluar([$this->linea(8, 'amazonia', null, true)]));
    }
}
