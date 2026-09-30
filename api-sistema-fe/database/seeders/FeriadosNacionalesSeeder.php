<?php

namespace Database\Seeders;

use App\Enums\Creditos\OrigenFeriado;
use App\Models\Creditos\Feriado;
use Illuminate\Database\Seeder;

/**
 * Módulo Créditos (plan 1.4) — feriados nacionales del Perú para el año en curso y el
 * siguiente. Corre al provisionar un tenant de giro 'creditos'; cada negocio agrega o
 * quita los suyos. Idempotente: nunca pisa una fecha ya registrada (propia o nacional).
 *
 * Lista vigente desde 2024 (incluye 7/06, 23/07, 6/08 y 9/12, agregados ese año).
 * Pendiente con el cliente (§11): confirmarla; si cambia la ley, se edita aquí.
 */
class FeriadosNacionalesSeeder extends Seeder
{
    private const FIJOS = [
        '01-01' => 'Año Nuevo',
        '05-01' => 'Día del Trabajo',
        '06-07' => 'Batalla de Arica y Día de la Bandera',
        '06-29' => 'San Pedro y San Pablo',
        '07-23' => 'Día de la Fuerza Aérea del Perú',
        '07-28' => 'Fiestas Patrias',
        '07-29' => 'Fiestas Patrias',
        '08-06' => 'Batalla de Junín',
        '08-30' => 'Santa Rosa de Lima',
        '10-08' => 'Combate de Angamos',
        '11-01' => 'Día de Todos los Santos',
        '12-08' => 'Inmaculada Concepción',
        '12-09' => 'Batalla de Ayacucho',
        '12-25' => 'Navidad',
    ];

    /** @param list<int>|null $anios por defecto el año actual y el siguiente */
    public function run(?array $anios = null): void
    {
        $anios ??= [(int) now()->year, (int) now()->year + 1];

        foreach ($anios as $anio) {
            foreach ($this->feriadosDe($anio) as $fecha => $descripcion) {
                Feriado::firstOrCreate(
                    ['fecha' => $fecha],
                    ['descripcion' => $descripcion, 'origen' => OrigenFeriado::Nacional],
                );
            }
        }
    }

    /** @return array<string, string> 'Y-m-d' => descripción */
    public function feriadosDe(int $anio): array
    {
        $feriados = [];
        foreach (self::FIJOS as $mesDia => $descripcion) {
            $feriados["{$anio}-{$mesDia}"] = $descripcion;
        }

        $pascua = $this->domingoDePascua($anio);
        $feriados[$pascua->modify('-3 days')->format('Y-m-d')] = 'Jueves Santo';
        $feriados[$pascua->modify('-2 days')->format('Y-m-d')] = 'Viernes Santo';
        ksort($feriados);

        return $feriados;
    }

    /** Algoritmo anónimo gregoriano (Meeus/Jones/Butcher); no depende de la extensión calendar. */
    private function domingoDePascua(int $anio): \DateTimeImmutable
    {
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = ($h + $l - 7 * $m + 114) % 31 + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, $dia));
    }
}
