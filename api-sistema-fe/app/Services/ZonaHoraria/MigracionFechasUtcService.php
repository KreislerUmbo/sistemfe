<?php

declare(strict_types=1);

namespace App\Services\ZonaHoraria;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Homogenización de fechas, F3: pasa a UTC (+5 h) los instantes que quedaron guardados en hora
 * Lima, fila por fila según DiagnosticoZonasService.
 *
 * - Tablas Lima (mutador o colateral): created_at, updated_at y sus columnas de instante del
 *   catálogo (sunat_sent_at, corrected_at, anulado_en, converted_at) de las filas decididas Lima.
 * - Cualquier otra tabla: solo created_at de las filas decididas Lima (updated_at mezclados se
 *   dejan, decisión del 08-oct-2026).
 * - Ambiguas y anomalías: no se tocan.
 * - Fechas de negocio (sales.date, fecha_pago…) nunca: no son instantes.
 *
 * Cada UPDATE queda registrado en ajustes_zona_horaria (tabla, columnas, ids) para poder
 * revertirlo exacto (revertir()). Lo corren las migraciones *_migrar_fechas_lima_a_utc
 * (tenant y central), dentro de la transacción de la migración.
 */
final class MigracionFechasUtcService
{
    public const TABLA_REGISTRO = 'ajustes_zona_horaria';
    private const LOTE = 1000;

    public function __construct(private readonly DiagnosticoZonasService $diagnostico)
    {
    }

    /**
     * @return list<array{tabla: string, columnas: list<string>, ids: list<int>}>
     */
    public function plan(ConnectionInterface $db, bool $central = false): array
    {
        // El catálogo describe los datos ANTES de migrar: en una base ya migrada no hay plan.
        return self::yaMigrada($db) ? [] : $this->calcularPlan($db, $central);
    }

    /** @return list<array{tabla: string, columnas: list<string>, ids: list<int>}> */
    private function calcularPlan(ConnectionInterface $db, bool $central): array
    {
        $plan = [];
        foreach ($this->diagnostico->diagnosticar($db, $central) as $t) {
            if ($t['a_corregir'] === []) {
                continue;
            }
            $plan[] = ['tabla' => $t['tabla'], 'columnas' => $this->columnas($db, $t['tabla'], $t['esperada'], $central), 'ids' => $t['a_corregir']];
        }

        return $plan;
    }

    /** @return list<array{tabla: string, columnas: list<string>, ids: list<int>}> lo aplicado */
    public function aplicar(ConnectionInterface $db, bool $central = false): array
    {
        // Se calcula entero ANTES de modificar nada. La migración crea la tabla de registro justo
        // antes de llamar acá, por eso no pasa por plan() (que la toma como "ya migrada").
        $plan = $this->calcularPlan($db, $central);
        foreach ($plan as $p) {
            $set = implode(', ', array_map(static fn (string $c): string => "\"{$c}\" = \"{$c}\" + interval '5 hours'", $p['columnas']));
            foreach (array_chunk($p['ids'], self::LOTE) as $lote) {
                $db->update('update "' . $p['tabla'] . '" set ' . $set . ' where id in (' . implode(',', array_map('intval', $lote)) . ')');
            }
            $db->table(self::TABLA_REGISTRO)->insert([
                'tabla' => $p['tabla'],
                'columnas' => json_encode($p['columnas']),
                'ids' => json_encode($p['ids']),
                'filas' => count($p['ids']),
                'created_at' => now(),
            ]);
        }

        return $plan;
    }

    public static function yaMigrada(ConnectionInterface $db): bool
    {
        return Schema::connection($db->getName())->hasTable(self::TABLA_REGISTRO);
    }

    /** Deshace exactamente lo registrado por aplicar(). */
    public function revertir(ConnectionInterface $db): void
    {
        foreach ($db->table(self::TABLA_REGISTRO)->orderByDesc('id')->get() as $r) {
            $columnas = json_decode($r->columnas, true);
            $set = implode(', ', array_map(static fn (string $c): string => "\"{$c}\" = \"{$c}\" - interval '5 hours'", $columnas));
            foreach (array_chunk(json_decode($r->ids, true), self::LOTE) as $lote) {
                $db->update('update "' . $r->tabla . '" set ' . $set . ' where id in (' . implode(',', array_map('intval', $lote)) . ')');
            }
        }
    }

    /** @return list<string> */
    private function columnas(ConnectionInterface $db, string $tabla, string $esperada, bool $central): array
    {
        if ($esperada !== CatalogoZonasFecha::LIMA) {
            return ['created_at'];
        }
        $extra = $central ? [] : (CatalogoZonasFecha::TABLAS_LIMA[$tabla] ?? []);
        $schema = Schema::connection($db->getName());

        return array_values(array_filter(
            array_merge(['created_at', 'updated_at'], $extra),
            static fn (string $c): bool => $schema->hasColumn($tabla, $c),
        ));
    }
}
