<?php

declare(strict_types=1);

namespace App\Services\ZonaHoraria;

use Illuminate\Database\ConnectionInterface;

/**
 * Clasifica en qué zona quedó guardado cada created_at, sin depender de logs.
 *
 * Una petición deja filas en varias tablas con segundos de diferencia. Las tablas de zona
 * conocida (Lima o UTC según CatalogoZonasFecha, nunca las mixtas) dan "anclas": instantes
 * reales en UTC. Para una fila con valor X:
 *   - hay un ancla de OTRA tabla a ±ventana de X        → la fila está en UTC
 *   - hay un ancla de OTRA tabla a ±ventana de X + 5 h  → la fila está en Lima
 *   - ambas → AMBIGUA;  ninguna → SIN_VECINO (no determinable).
 * Perú no tiene horario de verano: la diferencia es siempre 5 horas.
 *
 * Solo lee (SELECT). El comando fechas:diagnosticar además lo corre en una transacción
 * READ ONLY.
 */
final class DiagnosticoZonasService
{
    public const LIMA = 'LIMA';
    public const UTC = 'UTC';
    public const AMBIGUA = 'AMBIGUA';
    public const SIN_VECINO = 'SIN_VECINO';

    private const DESFASE = 5 * 3600;

    /** @var list<array{int, int}> anclas [epoch UTC real, índice de tabla], ordenadas */
    private array $anclas = [];

    /**
     * @return list<array{tabla: string, esperada: string, total: int, LIMA: int, UTC: int, AMBIGUA: int, SIN_VECINO: int, contradicciones: list<int>, filas: array<int, string>}>
     */
    public function diagnosticar(ConnectionInterface $db, bool $central = false, int $ventana = 3): array
    {
        $valores = [];
        foreach ($this->tablasConCreatedAt($db) as $tabla) {
            $valores[$tabla] = $db->table($tabla)->whereNotNull('created_at')->orderBy('id')->pluck('created_at', 'id')
                ->map(static fn ($v): string => substr((string) $v, 0, 19))->all();
        }

        $indices = array_flip(array_keys($valores));
        $this->anclas = [];
        foreach ($valores as $tabla => $filas) {
            $zona = CatalogoZonasFecha::zonaEsperada($tabla, $central);
            if ($zona === CatalogoZonasFecha::MIXTA) {
                continue;
            }
            foreach ($filas as $valor) {
                $this->anclas[] = [self::epoch($valor) + ($zona === CatalogoZonasFecha::LIMA ? self::DESFASE : 0), $indices[$tabla]];
            }
        }
        sort($this->anclas);

        $reporte = [];
        foreach ($valores as $tabla => $filas) {
            $esperada = CatalogoZonasFecha::zonaEsperada($tabla, $central);
            $fila = ['tabla' => $tabla, 'esperada' => $esperada, 'total' => count($filas),
                self::LIMA => 0, self::UTC => 0, self::AMBIGUA => 0, self::SIN_VECINO => 0, 'contradicciones' => [], 'filas' => []];
            foreach ($filas as $id => $valor) {
                $x = self::epoch($valor);
                $enUtc = $this->hayAncla($x, $ventana, $indices[$tabla]);
                $enLima = $this->hayAncla($x + self::DESFASE, $ventana, $indices[$tabla]);
                $clase = match (true) {
                    $enUtc && $enLima => self::AMBIGUA,
                    $enUtc => self::UTC,
                    $enLima => self::LIMA,
                    default => self::SIN_VECINO,
                };
                $fila[$clase]++;
                $fila['filas'][(int) $id] = $clase;
                if (($esperada === CatalogoZonasFecha::LIMA && $clase === self::UTC)
                    || ($esperada === CatalogoZonasFecha::UTC && $clase === self::LIMA)) {
                    $fila['contradicciones'][] = (int) $id;
                }
            }
            $reporte[] = $fila;
        }

        return $reporte;
    }

    /** @return list<string> */
    private function tablasConCreatedAt(ConnectionInterface $db): array
    {
        return array_map(static fn ($r): string => $r->table_name, $db->select(
            "select c.table_name from information_schema.columns c
             join information_schema.columns i on i.table_schema = c.table_schema and i.table_name = c.table_name and i.column_name = 'id'
             where c.table_schema = current_schema() and c.column_name = 'created_at'
               and c.data_type like 'timestamp%' order by c.table_name"
        ));
    }

    private function hayAncla(int $instante, int $ventana, int $excluirTabla): bool
    {
        // Primera ancla >= instante - ventana (búsqueda binaria), luego recorre hasta instante + ventana.
        $lo = 0;
        $hi = count($this->anclas);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->anclas[$mid][0] < $instante - $ventana) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        for ($i = $lo, $n = count($this->anclas); $i < $n && $this->anclas[$i][0] <= $instante + $ventana; $i++) {
            if ($this->anclas[$i][1] !== $excluirTabla) {
                return true;
            }
        }

        return false;
    }

    /** El texto tal cual está en la BD, leído como si fuera UTC (sin depender de la zona de PHP). */
    private static function epoch(string $valor): int
    {
        return (new \DateTimeImmutable($valor, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
