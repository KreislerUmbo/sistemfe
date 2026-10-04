<?php

declare(strict_types=1);

namespace App\Services\Creditos\Reportes;

use App\Models\Creditos\CreditoConfiguracion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Control y auditoría (04d, reporte 7): acciones sensibles del período — quién, cuándo, sobre qué
 * crédito, monto y motivo — y alerta por usuario que supere el umbral de anulaciones de pagos.
 * Lee credito_auditoria (solo inserción) más autorizaciones de excepción y castigos manuales, que
 * viven en sus propias tablas. Solo para quien ve toda la cartera (lo exige el controller).
 */
class ControlAuditoriaService
{
    /** Acciones que se revisan en el control (las de documentos o ver un DNI quedan fuera). */
    public const ACCIONES = [
        'pago.anular' => 'Anulación de pago',
        'pago.retroactivo' => 'Pago con fecha anterior',
        'pago.editar' => 'Edición de referencia de pago',
        'credito.corregir' => 'Corrección de crédito',
        'credito.anular' => 'Anulación de crédito',
        'mora.condonar' => 'Condonación de mora',
        'credito.reprogramar' => 'Reprogramación de fechas',
        'credito.revertir_castigo' => 'Reversión de castigo',
        'cartera.asignar' => 'Cambio de asesor/cobrador',
        'cartera.traspasar' => 'Traspaso de cartera',
        'saldo_favor.devolver' => 'Devolución de saldo a favor',
        'cliente.limites' => 'Límites del cliente',
        'configuracion.actualizar' => 'Cambio de configuración',
    ];

    /**
     * @param string|null $accion filtra por una acción (incluye 'autorizacion' y 'castigo')
     * @return array<string, mixed>
     */
    public function control(Periodo $periodo, ?string $accion = null, ?int $usuarioId = null): array
    {
        $nombres = User::withTrashed()->pluck('name', 'id');
        $numeros = DB::table('creditos')->pluck('numero_credito', 'id');
        $eventos = [];

        DB::table('credito_auditoria')
            ->whereIn('accion', array_keys(self::ACCIONES))
            ->when($accion !== null && isset(self::ACCIONES[$accion]), static fn ($q) => $q->where('accion', $accion))
            ->when($accion !== null && ! isset(self::ACCIONES[$accion]), static fn ($q) => $q->whereRaw('false'))
            ->when($usuarioId !== null, static fn ($q) => $q->where('usuario_id', $usuarioId))
            ->where('created_at', '>=', $periodo->desdeUtc())->where('created_at', '<', $periodo->hastaUtc())
            ->orderBy('created_at')
            ->get()
            ->each(function ($a) use (&$eventos, $nombres, $numeros): void {
                $despues = json_decode((string) $a->despues, true) ?: [];
                $eventos[] = [
                    'fecha' => $a->created_at,
                    'accion' => $a->accion,
                    'descripcion' => self::ACCIONES[$a->accion],
                    'usuario_id' => (int) $a->usuario_id,
                    'usuario' => $nombres[$a->usuario_id] ?? "Usuario #{$a->usuario_id}",
                    'credito_id' => $a->credito_id === null ? null : (int) $a->credito_id,
                    'numero_credito' => $a->credito_id === null ? null : ($numeros[$a->credito_id] ?? null),
                    'monto' => $despues['monto'] ?? $despues['condonado'] ?? $despues['devuelto'] ?? null,
                    'motivo' => $a->motivo,
                ];
            });

        if ($accion === null || $accion === 'autorizacion') {
            DB::table('credito_autorizaciones')
                ->when($usuarioId !== null, static fn ($q) => $q->where('autorizado_por', $usuarioId))
                ->where('created_at', '>=', $periodo->desdeUtc())->where('created_at', '<', $periodo->hastaUtc())
                ->get()
                ->each(function ($a) use (&$eventos, $nombres, $numeros): void {
                    $eventos[] = [
                        'fecha' => $a->created_at,
                        'accion' => 'autorizacion',
                        'descripcion' => 'Autorización de excepción (' . str_replace('_', ' ', (string) $a->regla) . ')',
                        'usuario_id' => (int) $a->autorizado_por,
                        'usuario' => $nombres[$a->autorizado_por] ?? "Usuario #{$a->autorizado_por}",
                        'credito_id' => (int) $a->credito_id,
                        'numero_credito' => $numeros[$a->credito_id] ?? null,
                        'monto' => null,
                        'motivo' => $a->motivo,
                    ];
                });
        }

        if ($accion === null || $accion === 'castigo') {
            DB::table('credito_castigos')->where('tipo', 'manual')
                ->when($usuarioId !== null, static fn ($q) => $q->where('castigado_por', $usuarioId))
                ->where('created_at', '>=', $periodo->desdeUtc())->where('created_at', '<', $periodo->hastaUtc())
                ->get()
                ->each(function ($k) use (&$eventos, $nombres, $numeros): void {
                    $eventos[] = [
                        'fecha' => $k->created_at,
                        'accion' => 'castigo',
                        'descripcion' => 'Castigo manual',
                        'usuario_id' => $k->castigado_por === null ? null : (int) $k->castigado_por,
                        'usuario' => $k->castigado_por === null ? 'Sistema' : ($nombres[$k->castigado_por] ?? "Usuario #{$k->castigado_por}"),
                        'credito_id' => (int) $k->credito_id,
                        'numero_credito' => $numeros[$k->credito_id] ?? null,
                        'monto' => null,
                        'motivo' => $k->motivo,
                    ];
                });
        }

        usort($eventos, static fn (array $x, array $y): int => strcmp((string) $y['fecha'], (string) $x['fecha']));
        // created_at está en UTC: se entrega en hora de Lima para mostrarlo tal cual.
        $eventos = array_map(static fn (array $e): array => [
            ...$e,
            'fecha' => \Carbon\CarbonImmutable::parse((string) $e['fecha'], 'UTC')->setTimezone(\App\Services\Creditos\Reloj::ZONA)->format('Y-m-d H:i'),
        ], $eventos);

        $umbral = (int) CreditoConfiguracion::actual()->umbral_alerta_anulaciones;
        $anulaciones = collect($eventos)->where('accion', 'pago.anular')->groupBy('usuario_id')
            ->map(static fn ($grupo): array => ['usuario' => $grupo->first()['usuario'], 'anulaciones' => $grupo->count()]);

        return [
            'periodo' => ['desde' => $periodo->desde->aTexto(), 'hasta' => $periodo->hasta->aTexto(), 'texto' => $periodo->texto()],
            'eventos' => $eventos,
            'umbral_anulaciones' => $umbral,
            'alertas' => $umbral > 0
                ? $anulaciones->filter(static fn (array $a): bool => $a['anulaciones'] > $umbral)->values()->all()
                : [],
            'acciones' => [...array_map(static fn (string $k, string $v): array => ['valor' => $k, 'texto' => $v], array_keys(self::ACCIONES), self::ACCIONES),
                ['valor' => 'autorizacion', 'texto' => 'Autorización de excepción'], ['valor' => 'castigo', 'texto' => 'Castigo manual']],
        ];
    }
}
