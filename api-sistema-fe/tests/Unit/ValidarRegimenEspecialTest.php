<?php

namespace Tests\Unit;

use App\Http\Controllers\Sale\SaleController;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Bloque B de la matriz tributaria (retencion_igv x naturaleza x exportación).
// validarRegimenEspecial() (SaleController.php) es un guard de solo-request
// (no toca BD), así que se invoca directo vía reflexión sobre una instancia
// sin constructor — no hace falta CashCorrectionService ni ningún otro
// colaborador del controller para ejercitar esta regla.
class ValidarRegimenEspecialTest extends TestCase
{
    private function invocar(array $payload): void
    {
        $controller = (new \ReflectionClass(SaleController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SaleController::class, 'validarRegimenEspecial');
        $method->setAccessible(true);
        $method->invoke($controller, new Request($payload));
    }

    #[DataProvider('casosPermitidos')]
    public function test_permite_combinaciones_validas(array $payload): void
    {
        $this->invocar($payload);
        $this->addToAssertionCount(1); // no lanzó excepción — caso válido
    }

    #[DataProvider('casosBloqueados')]
    public function test_bloquea_regimen_especial_sobre_operacion_no_gravada(array $payload, string $motivoEsperado): void
    {
        try {
            $this->invocar($payload);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString($motivoEsperado, $e->getMessage());
        }
    }

    public static function casosPermitidos(): array
    {
        return [
            'B1: sin régimen especial, gravado' => [[
                'retencion_igv' => 0, 'mto_oper_exoneradas' => 0, 'mto_oper_inafectas' => 0, 'is_exportacion' => 0,
            ]],
            'B2: retención sobre gravado' => [[
                'retencion_igv' => 1, 'mto_oper_exoneradas' => 0, 'mto_oper_inafectas' => 0, 'is_exportacion' => 0,
            ]],
            'B3: detracción sobre gravado' => [[
                'retencion_igv' => 2, 'mto_oper_exoneradas' => 0, 'mto_oper_inafectas' => 0, 'is_exportacion' => 0,
            ]],
            'B4: percepción sobre gravado' => [[
                'retencion_igv' => 3, 'mto_oper_exoneradas' => 0, 'mto_oper_inafectas' => 0, 'is_exportacion' => 0,
            ]],
            'B13: sin régimen especial, exonerado' => [[
                'retencion_igv' => 0, 'mto_oper_exoneradas' => 100, 'mto_oper_inafectas' => 0, 'is_exportacion' => 0,
            ]],
            'B14: sin régimen especial, exportación' => [[
                'retencion_igv' => 0, 'mto_oper_exoneradas' => 0, 'mto_oper_inafectas' => 0, 'is_exportacion' => 1,
            ]],
        ];
    }

    public static function casosBloqueados(): array
    {
        return [
            'B5: retención sobre exonerado' => [[
                'retencion_igv' => 1, 'mto_oper_exoneradas' => 100, 'mto_oper_inafectas' => 0, 'is_exportacion' => 0,
            ], 'operación exonerada'],
            'B6: detracción sobre exonerado' => [[
                'retencion_igv' => 2, 'mto_oper_exoneradas' => 100, 'mto_oper_inafectas' => 0, 'is_exportacion' => 0,
            ], 'operación exonerada'],
            'B7: percepción sobre exonerado' => [[
                'retencion_igv' => 3, 'mto_oper_exoneradas' => 100, 'mto_oper_inafectas' => 0, 'is_exportacion' => 0,
            ], 'operación exonerada'],
            'B8: retención sobre exonerado (general)' => [[
                'retencion_igv' => 1, 'mto_oper_exoneradas' => 50, 'mto_oper_inafectas' => 0, 'is_exportacion' => 0,
            ], 'operación exonerada'],
            'B9: detracción sobre inafecto' => [[
                'retencion_igv' => 2, 'mto_oper_exoneradas' => 0, 'mto_oper_inafectas' => 100, 'is_exportacion' => 0,
            ], 'operación inafecta'],
            'B10: retención sobre exportación' => [[
                'retencion_igv' => 1, 'mto_oper_exoneradas' => 0, 'mto_oper_inafectas' => 0, 'is_exportacion' => 1,
            ], 'operación de exportación'],
            'B11: detracción sobre exportación' => [[
                'retencion_igv' => 2, 'mto_oper_exoneradas' => 0, 'mto_oper_inafectas' => 0, 'is_exportacion' => 1,
            ], 'operación de exportación'],
            'B12: percepción sobre exportación' => [[
                'retencion_igv' => 3, 'mto_oper_exoneradas' => 0, 'mto_oper_inafectas' => 0, 'is_exportacion' => 1,
            ], 'operación de exportación'],
        ];
    }
}
