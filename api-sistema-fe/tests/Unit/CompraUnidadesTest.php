<?php

namespace Tests\Unit;

use App\Models\Compras\CompraLinea;
use PHPUnit\Framework\TestCase;

// T-162 (revisión): la pantalla de compras ofrece `UNITS` de types/products.ts; el backend valida con
// `CompraLinea::UNIDADES`. Si una se separa de la otra, la pantalla ofrece unidades que el backend rechaza.
class CompraUnidadesTest extends TestCase
{
    public function test_las_unidades_del_backend_son_las_de_la_pantalla(): void
    {
        $archivo = dirname(__DIR__, 3).'/admin-start-kit/src/types/products.ts';
        if (! is_file($archivo)) {
            $this->markTestSkipped('El frontend no está junto al backend.');
        }

        $texto = file_get_contents($archivo);
        $inicio = strpos($texto, 'export const UNITS');
        $this->assertNotFalse($inicio, 'No se encontró UNITS en types/products.ts.');
        $bloque = substr($texto, $inicio, strpos($texto, '];', $inicio) - $inicio);
        preg_match_all('/"code":\s*"([^"]*)"/', $bloque, $codigos);

        $this->assertSame($codigos[1], CompraLinea::UNIDADES);
    }
}
