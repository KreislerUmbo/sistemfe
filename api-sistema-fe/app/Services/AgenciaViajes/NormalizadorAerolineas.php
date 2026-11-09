<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\Aerolinea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// T-016 — Corrige los nombres de aerolínea ya guardados (texto libre) al nombre del catálogo
// común (T-015). Corre dentro de un tenant de Agencia. Compara con Aerolinea::normalizar();
// si el nombre coincide con una aerolínea unida, usa la de destino. Lo que no coincide con
// nada no se toca: se devuelve para listarlo. Cada cambio queda en `ajustes_aerolinea`
// (antes/después) y se puede revertir.
class NormalizadorAerolineas
{
    // tabla => columna que guarda el NOMBRE de la aerolínea.
    public const COLUMNAS = [
        'cotizacion_pasaje_aereo' => 'aerolinea',
        'opcion_mayorista' => 'vuelo_aerolinea',
        'paquetes_plantilla' => 'vuelo_aerolinea',
        'reserva_item_vuelo_pasajero' => 'vuelo_aerolinea_confirmada',
    ];

    // Separado para poder probar el servicio con una tabla de prueba.
    protected function columnas(): array
    {
        return self::COLUMNAS;
    }

    /**
     * @return array{cambios: list<array{tabla:string,id:int,columna:string,antes:string,despues:string}>, sin_coincidencia: array<string,int>}
     */
    public function simular(): array
    {
        $cambios = [];
        $sinCoincidencia = [];
        $cache = [];

        foreach ($this->columnas() as $tabla => $columna) {
            if (! Schema::hasColumn($tabla, $columna)) {
                continue;
            }

            $filas = DB::table($tabla)->whereNotNull($columna)->where($columna, '!=', '')->get(['id', $columna]);
            foreach ($filas as $fila) {
                $actual = $fila->{$columna};
                $clave = Aerolinea::normalizar($actual);
                if (! array_key_exists($clave, $cache)) {
                    $cache[$clave] = $this->nombreVigente($clave);
                }

                if ($cache[$clave] === null) {
                    $sinCoincidencia[$actual] = ($sinCoincidencia[$actual] ?? 0) + 1;
                } elseif ($cache[$clave] !== $actual) {
                    $cambios[] = [
                        'tabla' => $tabla, 'id' => (int) $fila->id, 'columna' => $columna,
                        'antes' => $actual, 'despues' => $cache[$clave],
                    ];
                }
            }
        }

        ksort($sinCoincidencia);

        return ['cambios' => $cambios, 'sin_coincidencia' => $sinCoincidencia];
    }

    // Aplica y registra los cambios (en una transacción). Devuelve el resultado de simular().
    public function aplicar(): array
    {
        $resultado = $this->simular();

        DB::transaction(function () use ($resultado) {
            foreach ($resultado['cambios'] as $c) {
                DB::table($c['tabla'])->where('id', $c['id'])->update([$c['columna'] => $c['despues']]);
                DB::table('ajustes_aerolinea')->insert([
                    'tabla' => $c['tabla'], 'registro_id' => $c['id'], 'columna' => $c['columna'],
                    'antes' => $c['antes'], 'despues' => $c['despues'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return $resultado;
    }

    /**
     * Deja los valores como estaban. Si alguien ya cambió el campo a mano después de la
     * corrección, no se pisa (se cuenta en `omitidos`).
     *
     * @return array{revertidos:int, omitidos:int}
     */
    public function revertir(): array
    {
        $revertidos = 0;
        $omitidos = 0;

        DB::transaction(function () use (&$revertidos, &$omitidos) {
            $ajustes = DB::table('ajustes_aerolinea')->whereNull('revertido_at')->orderByDesc('id')->get();
            foreach ($ajustes as $a) {
                $filas = DB::table($a->tabla)
                    ->where('id', $a->registro_id)
                    ->where($a->columna, $a->despues)
                    ->update([$a->columna => $a->antes]);

                $filas ? $revertidos++ : $omitidos++;
                DB::table('ajustes_aerolinea')->where('id', $a->id)->update(['revertido_at' => now(), 'updated_at' => now()]);
            }
        });

        return ['revertidos' => $revertidos, 'omitidos' => $omitidos];
    }

    // Nombre del catálogo para un nombre normalizado (siguiendo "unida a"), o null si no existe.
    private function nombreVigente(string $normalizado): ?string
    {
        $aerolinea = Aerolinea::where('nombre_normalizado', $normalizado)->first();
        for ($i = 0; $aerolinea && $aerolinea->unida_a_id && $i < 10; $i++) {
            $aerolinea = Aerolinea::find($aerolinea->unida_a_id);
        }

        return $aerolinea?->nombre;
    }
}
