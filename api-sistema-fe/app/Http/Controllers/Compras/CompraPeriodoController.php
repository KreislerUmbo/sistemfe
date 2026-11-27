<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Models\Compras\Compra;
use App\Models\Compras\CompraPeriodo;
use App\Models\User;
use App\Services\Compras\CompraException;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// T-135 (D6, D7) — cerrar y reabrir periodos de anotación. Nada se borra: cada cierre y cada
// reapertura queda además en `historial`.
class CompraPeriodoController extends Controller
{
    public function index()
    {
        $filas = CompraPeriodo::all()->keyBy('periodo');
        $cantidades = Compra::vigentes()->selectRaw('periodo, count(*) as cantidad')->groupBy('periodo')->pluck('cantidad', 'periodo');

        // El mes en curso y el anterior se listan aunque no tengan nada, para poder cerrarlos.
        $mes = HoraPeru::ahora()->startOfMonth();
        $periodos = $filas->keys()
            ->merge($cantidades->keys())
            ->merge([$mes->format('Y-m'), $mes->subMonth()->format('Y-m')])
            ->unique()->sortDesc()->values();

        $usuarios = User::withTrashed()->get(['id', 'name', 'surname'])
            ->mapWithKeys(fn (User $u) => [$u->id => trim("{$u->name} {$u->surname}")]);

        return response()->json([
            'periodos' => $periodos->map(fn (string $p) => $this->datos($p, $filas->get($p), (int) ($cantidades[$p] ?? 0), $usuarios)),
        ]);
    }

    public function cerrar(string $periodo)
    {
        $this->cambiar($periodo, CompraPeriodo::CERRADO, null);

        return response()->json(['code' => 200, 'message' => "Periodo {$periodo} cerrado"]);
    }

    public function reabrir(Request $request, string $periodo)
    {
        $motivo = trim((string) $request->input('motivo'));
        if ($motivo === '' || mb_strlen($motivo) > 255) {
            throw new CompraException('Indica el motivo de la reapertura (hasta 255 caracteres).');
        }

        $this->cambiar($periodo, CompraPeriodo::ABIERTO, $motivo);

        return response()->json(['code' => 200, 'message' => "Periodo {$periodo} reabierto"]);
    }

    private function cambiar(string $periodo, string $estado, ?string $motivo): void
    {
        if (! preg_match(CompraPeriodo::FORMATO, $periodo)) {
            throw new CompraException('El periodo va como AAAA-MM.');
        }

        DB::transaction(function () use ($periodo, $estado, $motivo) {
            $fila = CompraPeriodo::bloquear($periodo);
            if ($fila->estado === $estado) {
                throw new CompraException("El periodo {$periodo} ya está {$estado}.");
            }

            $cierra = $estado === CompraPeriodo::CERRADO;
            $userId = (int) auth('api')->id();
            $ahora = now();

            $fila->fill($cierra
                ? ['cerrado_at' => $ahora, 'cerrado_por' => $userId]
                : ['reabierto_at' => $ahora, 'reabierto_por' => $userId, 'motivo_reapertura' => $motivo]);
            $fila->estado = $estado;
            $fila->historial = [...($fila->historial ?? []), array_filter([
                'accion' => $cierra ? 'cierre' : 'reapertura',
                'user_id' => $userId,
                'at' => $ahora->toISOString(),
                'motivo' => $motivo,
            ], fn ($valor) => $valor !== null)];
            $fila->save();
        });
    }

    private function datos(string $periodo, ?CompraPeriodo $fila, int $cantidad, $usuarios): array
    {
        return [
            'periodo' => $periodo,
            'estado' => $fila?->estado ?? CompraPeriodo::ABIERTO,
            'compras' => $cantidad,
            'cerrado_at' => $fila?->cerrado_at?->toISOString(),
            'cerrado_por' => $usuarios[$fila?->cerrado_por] ?? null,
            'reabierto_at' => $fila?->reabierto_at?->toISOString(),
            'reabierto_por' => $usuarios[$fila?->reabierto_por] ?? null,
            'motivo_reapertura' => $fila?->motivo_reapertura,
            'historial' => collect($fila?->historial ?? [])->map(fn (array $e) => [
                'accion' => $e['accion'],
                'usuario' => $usuarios[$e['user_id']] ?? null,
                'at' => $e['at'],
                'motivo' => $e['motivo'] ?? null,
            ])->values(),
        ];
    }
}
