<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

// T-009 (CA5): cada movimiento de caja guarda la moneda real del dinero. Un CashMovement::create()
// sin 'currency' caería en el default 'PEN' de la columna: un cobro en dólares entraría a la caja
// como soles sin que nadie lo note. Este test falla si aparece uno.
class ReglaMonedaCajaTest extends TestCase
{
    public function test_todo_cash_movement_create_dice_la_moneda(): void
    {
        $base = dirname(__DIR__, 2);
        $hallazgos = [];
        $encontrados = 0;
        $archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/app'));
        foreach ($archivos as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }
            $relativo = str_replace('\\', '/', substr($archivo->getPathname(), strlen($base) + 1));
            $codigo = file_get_contents($archivo->getPathname());
            $desde = 0;
            while (($pos = strpos($codigo, 'CashMovement::create([', $desde)) !== false) {
                $encontrados++;
                $bloque = $this->bloqueDelArreglo($codigo, $pos + strlen('CashMovement::create('));
                if (! preg_match('/[\'"]currency[\'"]\s*=>/', $bloque)) {
                    $linea = substr_count(substr($codigo, 0, $pos), "\n") + 1;
                    $hallazgos[] = "{$relativo}:{$linea}";
                }
                $desde = $pos + 1;
            }
        }

        $this->assertGreaterThan(0, $encontrados, 'No se encontró ningún CashMovement::create( — ¿cambió la forma de crearlos?');
        $this->assertSame([], $hallazgos, "CashMovement::create sin 'currency' (PEN o USD, la moneda real del dinero):\n" . implode("\n", $hallazgos));
    }

    // Devuelve el texto del arreglo que empieza en $inicio ('['), hasta su ']' de cierre.
    private function bloqueDelArreglo(string $codigo, int $inicio): string
    {
        $nivel = 0;
        $largo = strlen($codigo);
        for ($i = $inicio; $i < $largo; $i++) {
            if ($codigo[$i] === '[') {
                $nivel++;
            } elseif ($codigo[$i] === ']') {
                $nivel--;
                if ($nivel === 0) {
                    return substr($codigo, $inicio, $i - $inicio + 1);
                }
            }
        }

        return substr($codigo, $inicio);
    }
}
