<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Http\Requests\Creditos\CreditoDatosRequest;
use App\Http\Requests\Creditos\CrearCreditoRequest;
use App\Http\Resources\Creditos\CreditoResource;
use App\Http\Resources\Creditos\CuotaResource;
use App\Http\Resources\Creditos\DetalleCreditoResource;
use App\Http\Resources\Creditos\FilaCreditoResource;
use App\Http\Resources\Creditos\FormatoCredito;
use App\Http\Resources\Creditos\PagoResource;
use App\Services\Creditos\ConsultaCreditoService;
use App\Services\Creditos\CreditoBorradorService;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\LimitesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Listado, preview, borradores, detalle y estado de cuenta. */
class CreditoController extends ControllerCreditos
{
    public function __construct(
        private readonly CreditoBorradorService $borradores,
        private readonly ConsultaCreditoService $consultas,
        private readonly LimitesService $limites,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::enum(CreditoEstado::class)],
            'cliente_id' => ['nullable', 'integer'],
            'con_atraso' => ['nullable', 'boolean'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'orden' => ['nullable', Rule::in(ConsultaCreditoService::ORDENES)],
            'direccion' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        return FilaCreditoResource::collection($this->consultas->listarConSituacion($filtros, $this->usuario()));
    }

    public function preview(CreditoDatosRequest $request): JsonResponse
    {
        $datos = $request->aDatos();
        $this->asegurarCliente($datos->clienteId);

        return response()->json([
            'cronograma' => FormatoCredito::cronograma($this->borradores->preview($datos)),
            'limites' => FormatoCredito::limites($this->limites->evaluar($datos->clienteId, $datos->montoCapital)),
        ]);
    }

    public function store(CrearCreditoRequest $request): JsonResponse
    {
        $this->asegurarCliente((int) $request->input('cliente_id'));

        return $this->idempotente($request, 'credito.crear', function () use ($request): array {
            $datos = $request->aDatos();
            $credito = $this->borradores->crear($datos, $this->usuario());

            return [[
                'credito' => (new CreditoResource($credito->load('cliente')))->resolve(),
                // Advertencia temprana (1.10); el bloqueo real ocurre al activar.
                'limites' => FormatoCredito::limites($this->limites->evaluar($datos->clienteId, $datos->montoCapital)),
            ], $credito->id];
        }, estado: 201);
    }

    public function update(CrearCreditoRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);
        $this->asegurarCliente((int) $request->input('cliente_id'));

        return $this->idempotente($request, 'credito.editar', fn (): array => [
            ['credito' => (new CreditoResource($this->borradores->actualizar($modelo, $request->aDatos())->load('cliente')))->resolve()],
            $modelo->id,
        ], ['credito' => $credito]);
    }

    public function show(int $credito): JsonResponse
    {
        return response()->json((new DetalleCreditoResource($this->consultas->detalle($this->credito($credito), $this->usuario())))->resolve());
    }

    public function estadoCuenta(int $credito): JsonResponse
    {
        $modelo = $this->consultas->estadoCuenta($this->credito($credito), $this->usuario());

        return response()->json([
            'credito' => (new CreditoResource($modelo))->resolve(),
            'cuotas' => CuotaResource::collection($modelo->cuotasVigentes)->resolve(),
            'pagos' => PagoResource::collection($modelo->pagos)->resolve(),
            'condonaciones' => $modelo->condonaciones->map(fn ($c) => [
                'numero_cuota' => $c->cuota?->numero_cuota, 'monto' => $c->monto, 'motivo' => $c->motivo,
                'estado' => $c->estado->value, 'fecha' => $c->created_at?->toIso8601String(),
            ])->values(),
            'cargos' => $modelo->cargos->map(fn ($c) => [
                'numero_cuota' => $c->cuota?->numero_cuota, 'monto' => $c->monto, 'estado' => $c->estado->value,
            ])->values(),
            'castigos' => $modelo->castigos->map(fn ($c) => [
                'fecha_castigo' => $c->fecha_castigo->format('Y-m-d'), 'fecha_reversion' => $c->fecha_reversion?->format('Y-m-d'),
                'tipo' => $c->tipo->value, 'motivo' => $c->motivo,
            ])->values(),
            'reprogramaciones' => $modelo->reprogramaciones->map(fn ($r) => [
                'fecha' => $r->created_at?->toIso8601String(), 'motivo' => $r->motivo, 'cargo' => $r->cargo_monto,
                'accion_mora' => $r->accion_mora->value,
                'cuotas' => $r->cuotas->map(fn ($q) => [
                    'cuota_id' => $q->cuota_id, 'fecha_anterior' => $q->fecha_anterior->format('Y-m-d'), 'fecha_nueva' => $q->fecha_nueva->format('Y-m-d'),
                ])->values(),
            ])->values(),
            'total_pagado' => Dinero::aSoles($modelo->pagos->where('estado.value', 'valido')->sum(fn ($p) => Dinero::aCentavos($p->monto_aplicado))),
        ]);
    }
}
