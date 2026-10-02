<?php

declare(strict_types=1);

namespace App\Services\Creditos\Reportes;

/**
 * Convierte cada reporte (04d) en una tabla genérica para exportar: título, subtítulo y secciones
 * con columnas, filas y totales. Un solo PDF y un solo Excel sirven para los 7 reportes; la
 * "hoja de ruta" de la agenda es una sección por cobrador con salto de página.
 *
 * @phpstan-type Seccion array{titulo: string|null, columnas: list<array{0: string, 1: string}>, filas: list<list<string|int|null>>, totales: list<string|int|null>|null, salto: bool}
 */
final class TablasReporte
{
    public const TITULOS = [
        'agenda' => 'Agenda de cobranza',
        'cartera' => 'Cartera de créditos',
        'morosidad' => 'Morosidad',
        'ingresos' => 'Ingresos y desembolsos',
        'asesores' => 'Resultados por asesor',
        'castigados' => 'Castigados y recuperos',
        'control' => 'Control y auditoría',
    ];

    private const RANGOS = ['al_dia' => 'Al día', '1-7' => '1-7 días', '8-15' => '8-15 días', '16-30' => '16-30 días', '31-60' => '31-60 días', '60+' => 'Más de 60'];
    private const IZQ = 'izq';
    private const DER = 'der';

    /** @return array{titulo: string, subtitulo: string, secciones: list<array<string, mixed>>} */
    public static function de(string $reporte, array $d): array
    {
        return match ($reporte) {
            'agenda' => self::agenda($d),
            'cartera' => self::cartera($d),
            'morosidad' => self::morosidad($d),
            'ingresos' => self::ingresos($d),
            'asesores' => self::asesores($d),
            'castigados' => self::castigados($d),
            'control' => self::control($d),
        };
    }

    private static function agenda(array $d): array
    {
        // Hoja de ruta: una sección (página) por cobrador, con sus días dentro.
        $porCobrador = [];
        foreach ($d['dias'] as $dia) {
            foreach ($dia['cobradores'] as $c) {
                foreach ($c['filas'] as $f) {
                    $porCobrador[$c['cobrador']][] = [
                        $dia['dia'] === null ? 'Atrasado' : self::fecha($dia['dia']),
                        $f['cliente']['nombre'],
                        trim(($f['cliente']['telefono'] ?? '') . ($f['cliente']['telefono_alterno'] ? ' / ' . $f['cliente']['telefono_alterno'] : '')),
                        trim(($f['cliente']['direccion_cobro'] ?? '') . ($f['cliente']['referencia'] ? ' — ' . $f['cliente']['referencia'] : '')),
                        $f['cliente']['distrito'],
                        ($f['numero_credito'] ?? '') . ' · ' . $f['numero_cuota'] . '/' . $f['cuotas_total'],
                        self::soles($f['pendiente']) . ($f['mora'] !== '0.00' ? ' + mora ' . self::soles($f['mora']) : ''),
                        $f['con_atraso'] ? 'Sí' : '',
                        '',   // casilla para marcar "cobrado" en papel
                    ];
                }
            }
        }
        ksort($porCobrador);
        $columnas = [['Día', self::IZQ], ['Cliente', self::IZQ], ['Teléfono', self::IZQ], ['Dirección', self::IZQ], ['Distrito', self::IZQ],
            ['Crédito · cuota', self::IZQ], ['A cobrar', self::DER], ['Con atraso', self::IZQ], ['Cobrado', self::IZQ]];

        return [
            'titulo' => self::TITULOS['agenda'],
            'subtitulo' => $d['periodo']['texto'] . ' · ' . $d['totales']['cuotas'] . ' cuota(s), ' . $d['totales']['clientes'] . ' cliente(s), ' . self::soles($d['totales']['monto']),
            'secciones' => array_map(static fn (string $cobrador, array $filas): array => [
                'titulo' => 'Cobrador: ' . $cobrador . ' · ' . count($filas) . ' cuota(s)',
                'columnas' => $columnas,
                'filas' => $filas,
                'totales' => null,
                'salto' => true,
            ], array_keys($porCobrador), $porCobrador),
        ];
    }

    private static function cartera(array $d): array
    {
        $t = $d['totales'];

        return [
            'titulo' => self::TITULOS['cartera'],
            'subtitulo' => 'Al ' . self::fecha($d['corte']) . ' · ' . $t['creditos'] . ' crédito(s)',
            'secciones' => [[
                'titulo' => null,
                'columnas' => [['Crédito', self::IZQ], ['Cliente', self::IZQ], ['Asesor', self::IZQ], ['Estado', self::IZQ], ['Prestado', self::DER],
                    ['Saldo capital', self::DER], ['Saldo interés', self::DER], ['Mora', self::DER], ['Pagos', self::IZQ], ['Próximo', self::IZQ], ['Atraso', self::DER]],
                'filas' => array_map(static fn (array $f): array => [
                    $f['numero_credito'], $f['cliente'], $f['asesor'] ?? 'Sin asesor', ucfirst($f['estado']), self::soles($f['capital_prestado']),
                    self::soles($f['saldo_capital']), self::soles($f['saldo_interes']), self::soles($f['mora']),
                    $f['cuotas_pagadas'] . '/' . $f['cuotas_total'], $f['proximo_vencimiento'] ? self::fecha($f['proximo_vencimiento']) : '—',
                    $f['dias_atraso'] > 0 ? $f['dias_atraso'] . ' d' : '—',
                ], $d['filas']),
                'totales' => ['Total', '', '', '', self::soles($t['capital_prestado']), self::soles($t['saldo_capital']), self::soles($t['saldo_interes']), self::soles($t['mora']), '', '', ''],
                'salto' => false,
            ]],
        ];
    }

    private static function morosidad(array $d): array
    {
        $tabla = static fn (array $g): array => array_map(static fn (array $r): array => [
            self::RANGOS[$r['rango']], $r['creditos'], self::soles($r['saldo']),
        ], $g['rangos']);
        $columnas = [['Rango de atraso', self::IZQ], ['Créditos', self::DER], ['Saldo capital', self::DER]];

        return [
            'titulo' => self::TITULOS['morosidad'],
            'subtitulo' => 'Al ' . self::fecha($d['corte']) . ' · Cartera en riesgo ' . $d['total']['porcentaje_riesgo'] . '%',
            'secciones' => [
                ['titulo' => 'Toda la cartera', 'columnas' => $columnas, 'filas' => $tabla($d['total']),
                    'totales' => ['Total · en riesgo ' . $d['total']['porcentaje_riesgo'] . '%', '', self::soles($d['total']['saldo_total'])], 'salto' => false],
                ...array_map(static fn (array $a): array => [
                    'titulo' => 'Asesor: ' . $a['asesor'], 'columnas' => $columnas, 'filas' => $tabla($a),
                    'totales' => ['Total · en riesgo ' . $a['porcentaje_riesgo'] . '%', '', self::soles($a['saldo_total'])], 'salto' => false,
                ], $d['por_asesor']),
            ],
        ];
    }

    private static function ingresos(array $d): array
    {
        $fila = static fn (string $periodo, array $g): array => [
            $periodo, self::soles($g['desembolsado']), $g['creditos_entregados'], self::soles($g['capital']), self::soles($g['interes']),
            self::soles($g['mora']), self::soles($g['cargo']), self::soles($g['cobrado']), self::soles($g['mora_condonada']),
            self::soles($g['interes_descontado']), self::soles($g['saldo_favor_devuelto']),
        ];
        $nombre = static fn (string $clave): string => match ($d['agrupacion']) {
            'mes' => substr($clave, 5, 2) . '/' . substr($clave, 0, 4),
            'semana' => 'Semana del ' . self::fecha($clave),
            default => self::fecha($clave),
        };

        return [
            'titulo' => self::TITULOS['ingresos'],
            'subtitulo' => $d['periodo']['texto'] . ' · base caja (lo cobrado, no lo devengado)',
            'secciones' => [[
                'titulo' => null,
                'columnas' => [['Período', self::IZQ], ['Prestado', self::DER], ['Créditos', self::DER], ['Capital cobrado', self::DER], ['Interés', self::DER],
                    ['Mora', self::DER], ['Cargos', self::DER], ['Total cobrado', self::DER], ['Mora condonada', self::DER], ['Interés descontado', self::DER], ['Saldo a favor devuelto', self::DER]],
                'filas' => array_map(static fn (array $f): array => $fila($nombre($f['periodo']), $f), $d['filas']),
                'totales' => $fila('Total', $d['totales']),
                'salto' => false,
            ]],
        ];
    }

    private static function asesores(array $d): array
    {
        return [
            'titulo' => self::TITULOS['asesores'],
            'subtitulo' => $d['periodo']['texto'] . ' · colocado y cobrado en el período; cartera al día de hoy',
            'secciones' => [[
                'titulo' => null,
                'columnas' => [['Asesor', self::IZQ], ['Créditos colocados', self::DER], ['Capital colocado', self::DER], ['Cobrado de lo colocado', self::DER],
                    ['Cartera: créditos', self::DER], ['Cartera: saldo', self::DER], ['En riesgo', self::DER]],
                'filas' => array_map(static fn (array $f): array => [
                    $f['asesor'], $f['colocados'], self::soles($f['capital_colocado']), self::soles($f['cobrado']),
                    $f['cartera_creditos'], self::soles($f['cartera_saldo']), $f['porcentaje_riesgo'] . '%',
                ], $d['filas']),
                'totales' => null,
                'salto' => false,
            ]],
        ];
    }

    private static function castigados(array $d): array
    {
        return [
            'titulo' => self::TITULOS['castigados'],
            'subtitulo' => $d['periodo']['texto'] . ' · recuperado ' . self::soles($d['total_recuperado']),
            'secciones' => [
                ['titulo' => 'Castigos del período', 'columnas' => [['Fecha', self::IZQ], ['Crédito', self::IZQ], ['Cliente', self::IZQ], ['Tipo', self::IZQ],
                    ['Saldo hoy', self::DER], ['Estado', self::IZQ], ['Motivo', self::IZQ]],
                    'filas' => array_map(static fn (array $k): array => [self::fecha($k['fecha_castigo']), $k['numero_credito'], $k['cliente'],
                        $k['tipo'] === 'manual' ? 'Manual' : 'Automático', self::soles($k['saldo_hoy']), $k['revertido'] ? 'Revertido' : 'Vigente', $k['motivo']], $d['castigos']),
                    'totales' => null, 'salto' => false],
                ['titulo' => 'Recuperos (pagos de créditos castigados)', 'columnas' => [['Fecha', self::IZQ], ['Recibo', self::IZQ], ['Crédito', self::IZQ],
                    ['Cliente', self::IZQ], ['Monto', self::DER]],
                    'filas' => array_map(static fn (array $p): array => [self::fecha($p['fecha']), $p['numero_recibo'], $p['numero_credito'], $p['cliente'], self::soles($p['monto'])], $d['recuperos']),
                    'totales' => ['Total recuperado', '', '', '', self::soles($d['total_recuperado'])], 'salto' => false],
            ],
        ];
    }

    private static function control(array $d): array
    {
        return [
            'titulo' => self::TITULOS['control'],
            'subtitulo' => $d['periodo']['texto'] . ' · ' . count($d['eventos']) . ' acción(es)'
                . ($d['alertas'] ? ' · ALERTA: ' . implode(', ', array_map(static fn (array $a): string => $a['usuario'] . ' (' . $a['anulaciones'] . ' anulaciones)', $d['alertas'])) : ''),
            'secciones' => [[
                'titulo' => null,
                'columnas' => [['Fecha', self::IZQ], ['Acción', self::IZQ], ['Usuario', self::IZQ], ['Crédito', self::IZQ], ['Monto', self::DER], ['Motivo', self::IZQ]],
                'filas' => array_map(static fn (array $e): array => [
                    self::fecha(substr($e['fecha'], 0, 10)) . substr($e['fecha'], 10), $e['descripcion'], $e['usuario'], $e['numero_credito'] ?? '—',
                    $e['monto'] !== null ? self::soles((string) $e['monto']) : '—', $e['motivo'],
                ], $d['eventos']),
                'totales' => null,
                'salto' => false,
            ]],
        ];
    }

    private static function soles(string $monto): string
    {
        $negativo = str_starts_with($monto, '-');
        [$entero, $decimales] = array_pad(explode('.', ltrim($monto, '-')), 2, '00');

        return ($negativo ? '-' : '') . 'S/ ' . number_format((int) $entero, 0, '.', ',') . '.' . str_pad(substr($decimales, 0, 2), 2, '0');
    }

    private static function fecha(string $ymd): string
    {
        return substr($ymd, 8, 2) . '/' . substr($ymd, 5, 2) . '/' . substr($ymd, 0, 4);
    }
}
