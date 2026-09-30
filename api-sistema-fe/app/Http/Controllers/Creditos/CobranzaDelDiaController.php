<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Enums\Creditos\ModoAsignacionCartera;
use App\Models\Creditos\CarteraAsignacion;
use App\Models\Creditos\CreditoClienteFicha;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\ConsultaCreditoService;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\CobroDelDia;
use App\Services\Creditos\Dto\DetalleCredito;
use App\Services\Creditos\Reloj;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cobranza del día (mockup 4): pendientes de su alcance (vencidos primero, luego los que vencen
 * hoy), lo ya cobrado hoy y el resumen, todo sumado aquí (00 §4). Con creditos.ver_todos se
 * puede filtrar por cobrador (cobrador_id=0: clientes sin cobrador asignado).
 */
class CobranzaDelDiaController extends ControllerCreditos
{
    public function __construct(
        private readonly ConsultaCreditoService $consultas,
        private readonly Reloj $reloj,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['cobrador_id' => ['nullable', 'integer', 'min:0']]);
        $usuario = $this->usuario();
        $pendientes = $this->consultas->cobranzaDelDia($usuario);
        $cobrados = $this->consultas->cobradosHoy($usuario);

        $clientes = array_unique([
            ...array_map(static fn (DetalleCredito $d): int => $d->credito->cliente_id, $pendientes),
            ...array_map(static fn (CobroDelDia $c): int => $c->credito->cliente_id, $cobrados),
        ]);
        $cobradorDe = CarteraAsignacion::vigentes()->where('tipo', ModoAsignacionCartera::Cliente)
            ->whereIn('referencia_id', $clientes)->pluck('cobrador_id', 'referencia_id');
        $nombres = User::whereIn('id', $cobradorDe->unique())->pluck('name', 'id');

        $verTodos = $usuario->can(AlcanceCartera::PERMISO_VER_TODOS);
        if ($verTodos && $request->filled('cobrador_id')) {
            $filtro = (int) $request->input('cobrador_id');
            $delCobrador = static fn (int $clienteId): bool => (int) ($cobradorDe[$clienteId] ?? 0) === $filtro;
            $pendientes = array_values(array_filter($pendientes, static fn (DetalleCredito $d): bool => $delCobrador($d->credito->cliente_id)));
            $cobrados = array_values(array_filter($cobrados, static fn (CobroDelDia $c): bool => $delCobrador($c->credito->cliente_id)));
        }

        $fichas = CreditoClienteFicha::whereIn('cliente_id', $clientes)->get()->keyBy('cliente_id');
        $cobrador = static function (int $clienteId) use ($cobradorDe, $nombres): ?array {
            $id = $cobradorDe[$clienteId] ?? null;

            return $id === null ? null : ['id' => (int) $id, 'nombre' => $nombres[$id] ?? null];
        };

        return response()->json([
            'fecha' => $this->reloj->hoy()->aTexto(),
            'resumen' => [
                'por_cobrar' => Dinero::aSoles(array_sum(array_map(static fn (DetalleCredito $d): int => $d->exigible, $pendientes))),
                'cobrado' => Dinero::aSoles(array_sum(array_map(static fn (CobroDelDia $c): int => $c->montoAplicado, $cobrados))),
                // Al día = pagaron hoy y ya no deben nada exigible (un abono parcial no cuenta).
                'clientes_al_dia' => count(array_diff(
                    array_unique(array_map(static fn (CobroDelDia $c): int => $c->credito->cliente_id, $cobrados)),
                    array_map(static fn (DetalleCredito $d): int => $d->credito->cliente_id, $pendientes),
                )),
                'clientes_total' => count(array_unique([
                    ...array_map(static fn (DetalleCredito $d): int => $d->credito->cliente_id, $pendientes),
                    ...array_map(static fn (CobroDelDia $c): int => $c->credito->cliente_id, $cobrados),
                ])),
            ],
            // Solo quien ve toda la cartera puede filtrar por cobrador.
            'cobradores' => $verTodos
                ? $nombres->map(static fn (string $nombre, int $id): array => ['id' => $id, 'nombre' => $nombre])->values()
                : [],
            'data' => array_map(static function (DetalleCredito $d) use ($fichas, $cobrador): array {
                $ficha = $fichas[$d->credito->cliente_id] ?? null;
                $cliente = $d->credito->cliente;

                return [
                    'credito_id' => $d->credito->id,
                    'numero_credito' => $d->credito->numero_credito,
                    'cliente' => [
                        'id' => $cliente->id,
                        'nombre' => $cliente->full_name,
                        'telefono' => $cliente->phone,
                        'telefono_alterno' => $ficha?->telefono_alterno,
                        'direccion_cobro' => $ficha?->direccion_cobro ?? $cliente->address ?? null,
                        'referencia' => $ficha?->referencia,
                        'latitud' => $ficha?->latitud,
                        'longitud' => $ficha?->longitud,
                    ],
                    'cobrador' => $cobrador($d->credito->cliente_id),
                    // "vencido": cuotas vencidas o mora pendiente; "hoy": solo lo que vence hoy.
                    'estado' => $d->diasAtraso > 0 || ($d->saldo?->cuotasVencidas ?? 0) > 0 ? 'vencido' : 'hoy',
                    'exigible_hoy' => Dinero::aSoles($d->exigible),
                    'mora_pendiente' => Dinero::aSoles($d->situacion?->moraPendienteTotal() ?? 0),
                    'dias_atraso' => $d->diasAtraso,
                    'cuotas_vencidas' => $d->saldo?->cuotasVencidas ?? 0,
                ];
            }, $pendientes),
            'cobrados' => array_map(static fn (CobroDelDia $c): array => [
                'credito_id' => $c->credito->id,
                'numero_credito' => $c->credito->numero_credito,
                'cliente' => ['id' => $c->credito->cliente->id, 'nombre' => $c->credito->cliente->full_name],
                'cobrador' => $cobrador($c->credito->cliente_id),
                'monto_aplicado' => Dinero::aSoles($c->montoAplicado),
                'ultimo_pago' => $c->ultimoPago->format('H:i'),
                'metodos' => $c->metodos,
            ], $cobrados),
        ]);
    }
}
