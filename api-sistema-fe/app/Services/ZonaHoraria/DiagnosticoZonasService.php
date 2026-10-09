<?php

declare(strict_types=1);

namespace App\Services\ZonaHoraria;

use Illuminate\Database\ConnectionInterface;

/**
 * Decide en qué zona quedó guardado cada created_at, sin depender de logs.
 *
 * El contagio a hora Lima solo ocurre en una petición donde se guardó un modelo "Lima" (su
 * mutador hace date_default_timezone_set). Ese guardado SIEMPRE deja huella en una tabla Lima
 * (created_at o updated_at, escritos por el mutador). Esas huellas son las anclas: instantes
 * reales (valor + 5 h). Las tablas UTC NO sirven de ancla: pueden estar contagiadas (al crear un
 * tenant, por ejemplo, todo queda en Lima desde que una migración crea el producto especial).
 *
 * Para una fila con valor X (excluyendo sus propias huellas):
 *   - huella Lima a ±ventana de X + 5 h → se guardó en Lima (después del modelo Lima)
 *   - huella Lima a ±ventana de X       → se guardó en UTC (antes del modelo Lima)
 *   - ambas → AMBIGUA (no se toca);  ninguna → SIN_HUELLA.
 * Decisión: SIN_HUELLA en una tabla Lima → LIMA (lo escribió su mutador); en cualquier otra →
 * UTC (sin modelo Lima en esa petición no hubo contagio). Límite conocido: si el modelo Lima de
 * esa petición se volvió a editar después, su updated_at ya no la recuerda.
 * Perú no tiene horario de verano: la diferencia es siempre 5 horas.
 *
 * Solo lee (SELECT). fechas:diagnosticar además lo corre en una transacción READ ONLY.
 */
final class DiagnosticoZonasService
{
    public const LIMA = 'LIMA';
    public const UTC = 'UTC';
    public const AMBIGUA = 'AMBIGUA';
    public const SIN_HUELLA = 'SIN_HUELLA';

    private const DESFASE = 5 * 3600;

    /** @var list<array{int, string}> anclas [instante real (epoch UTC), "tabla#id"], ordenadas */
    private array $anclas = [];

    /**
     * @return list<array{tabla: string, esperada: string, total: int, LIMA: int, UTC: int, AMBIGUA: int, SIN_HUELLA: int,
     *   a_corregir: list<int>, ambiguas: list<int>, anomalias: list<int>, filas: array<int, string>}>
     *   a_corregir: ids cuyo created_at está en Lima (la migración les suma 5 h);
     *   anomalias: filas de una tabla Lima que en realidad están en UTC (insertadas sin su modelo).
     */
    public function diagnosticar(ConnectionInterface $db, bool $central = false, int $ventana = 3): array
    {
        $valores = [];
        $this->anclas = [];
        foreach ($this->tablas($db) as $tabla => $tieneUpdatedAt) {
            $columnas = $tieneUpdatedAt ? ['id', 'created_at', 'updated_at'] : ['id', 'created_at'];
            $filas = $db->table($tabla)->whereNotNull('created_at')->orderBy('id')->get($columnas);
            $valores[$tabla] = [];
            $esLima = $this->esTablaConMutador($tabla, $central);
            foreach ($filas as $f) {
                $valores[$tabla][(int) $f->id] = substr((string) $f->created_at, 0, 19);
                if ($esLima) {
                    $this->anclas[] = [self::epoch((string) $f->created_at) + self::DESFASE, "{$tabla}#{$f->id}"];
                }
            }
        }
        sort($this->anclas);

        $reporte = [];
        foreach ($valores as $tabla => $filas) {
            $esperada = CatalogoZonasFecha::zonaEsperada($tabla, $central);
            $r = ['tabla' => $tabla, 'esperada' => $esperada, 'total' => count($filas),
                self::LIMA => 0, self::UTC => 0, self::AMBIGUA => 0, self::SIN_HUELLA => 0,
                'a_corregir' => [], 'ambiguas' => [], 'anomalias' => [], 'filas' => []];
            foreach ($filas as $id => $valor) {
                $x = self::epoch($valor);
                $propia = "{$tabla}#{$id}";
                $huellaLima = $this->hayAncla($x + self::DESFASE, $ventana, $propia);
                $huellaUtc = $this->hayAncla($x, $ventana, $propia);
                $clase = match (true) {
                    $huellaLima && $huellaUtc => self::AMBIGUA,
                    $huellaLima => self::LIMA,
                    $huellaUtc => self::UTC,
                    default => self::SIN_HUELLA,
                };
                $r[$clase]++;
                $decision = $clase === self::SIN_HUELLA
                    ? ($esperada === CatalogoZonasFecha::LIMA ? self::LIMA : self::UTC)
                    : $clase;
                $r['filas'][$id] = $decision;
                if ($decision === self::LIMA) {
                    $r['a_corregir'][] = $id;
                } elseif ($decision === self::AMBIGUA) {
                    $r['ambiguas'][] = $id;
                } elseif ($esperada === CatalogoZonasFecha::LIMA) {
                    $r['anomalias'][] = $id;
                }
            }
            $reporte[] = $r;
        }

        return $reporte;
    }

    private function esTablaConMutador(string $tabla, bool $central): bool
    {
        return $central
            ? in_array($tabla, CatalogoZonasFecha::TABLAS_LIMA_CENTRAL, true)
            : isset(CatalogoZonasFecha::TABLAS_LIMA[$tabla]);
    }

    /** @return array<string, bool> tablas con id y created_at => si tiene updated_at */
    private function tablas(ConnectionInterface $db): array
    {
        $filas = $db->select(
            "select c.table_name, exists(select 1 from information_schema.columns u where u.table_schema = c.table_schema
                    and u.table_name = c.table_name and u.column_name = 'updated_at' and u.data_type like 'timestamp%') as tiene_updated_at
             from information_schema.columns c
             join information_schema.columns i on i.table_schema = c.table_schema and i.table_name = c.table_name and i.column_name = 'id'
             where c.table_schema = current_schema() and c.column_name = 'created_at'
               and c.data_type like 'timestamp%' order by c.table_name"
        );
        $tablas = [];
        foreach ($filas as $f) {
            $tablas[$f->table_name] = (bool) $f->tiene_updated_at;
        }

        return $tablas;
    }

    private function hayAncla(int $instante, int $ventana, string $excluir): bool
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
            if ($this->anclas[$i][1] !== $excluir) {
                return true;
            }
        }

        return false;
    }

    /** El texto tal cual está en la BD, leído como si fuera UTC (sin depender de la zona de PHP). */
    private static function epoch(string $valor): int
    {
        return (new \DateTimeImmutable(substr($valor, 0, 19), new \DateTimeZone('UTC')))->getTimestamp();
    }
}
