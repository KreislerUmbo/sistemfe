<?php

namespace Tests\Unit\AgenciaViajes;

use App\Services\AgenciaViajes\CriterioTributarioService;
use PHPUnit\Framework\TestCase;

// T-085 — el criterio extraído de ReservaFacturacionController. Los casos de
// integración (con reservas reales) siguen en ReservaFacturacionTest.
// T-091 (D-AGE-035): la mezcla y la Amazonía sin confirmar ya no bloquean;
// solo exportación/extranjero, sola o mezclada.
class CriterioTributarioServiceTest extends TestCase
{
    private function linea($id, string $destino): array
    {
        return ['id' => $id, 'destino' => $destino];
    }

    private function evaluar(array $lineas): ?array
    {
        return (new CriterioTributarioService)->evaluar(collect($lineas));
    }

    public function test_un_solo_destino_nacional_no_bloquea(): void
    {
        $this->assertNull($this->evaluar([$this->linea(1, 'nacional'), $this->linea(2, 'nacional')]));
    }

    public function test_mezcla_de_destinos_no_bloquea(): void
    {
        $this->assertNull($this->evaluar([$this->linea(1, 'amazonia'), $this->linea(2, 'nacional')]));
    }

    public function test_amazonia_sola_no_bloquea_sin_confirmar(): void
    {
        $this->assertNull($this->evaluar([$this->linea(7, 'amazonia'), $this->linea(8, 'amazonia')]));
    }

    // CA3: exportación, sola o con otros, con el mensaje de siempre.
    public function test_extranjero_solo_o_mezclado_bloquea_con_su_mensaje(): void
    {
        $solo = $this->evaluar([$this->linea(1, 'extranjero')]);
        $mezclado = $this->evaluar([$this->linea(1, 'extranjero'), $this->linea(2, 'amazonia'), $this->linea(3, 'nacional')]);

        $this->assertSame(CriterioTributarioService::MENSAJE_TRATAMIENTO_TRIBUTARIO_EXTRANJERO, $solo['motivo']);
        $this->assertSame(CriterioTributarioService::MENSAJE_TRATAMIENTO_TRIBUTARIO_EXTRANJERO, $mezclado['motivo']);
        $this->assertEqualsCanonicalizing(['extranjero', 'amazonia', 'nacional'], $mezclado['destinos']);
        $this->assertArrayNotHasKey('sin_confirmar_ids', $mezclado);
    }
}
