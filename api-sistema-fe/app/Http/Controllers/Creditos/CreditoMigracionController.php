<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Http\Requests\Creditos\MigrarCreditoRequest;
use App\Http\Resources\Creditos\CreditoResource;
use App\Http\Resources\Creditos\FormatoCredito;
use App\Services\Creditos\LimitesService;
use App\Services\Creditos\MigracionService;
use Illuminate\Http\JsonResponse;

/** Registrar un crédito existente (00 1.12): los límites solo advierten. */
class CreditoMigracionController extends ControllerCreditos
{
    public function __construct(
        private readonly MigracionService $migraciones,
        private readonly LimitesService $limites,
    ) {
    }

    public function store(MigrarCreditoRequest $request): JsonResponse
    {
        return $this->idempotente($request, 'credito.migrar', function () use ($request): array {
            $datos = $request->aDatos();
            $advertencias = FormatoCredito::limites($this->limites->evaluar($datos->clienteId, $datos->montoCapital, esMigracion: true));
            $credito = $this->migraciones->migrar(
                $datos, $request->cuotasATiempo(), $request->pagosDetallados(), $request->boolean('condonar_mora'),
                (string) $request->input('clave_idempotencia'), $this->usuario(),
            );

            return [[
                'credito' => (new CreditoResource($credito->load('cliente')->cargarCuotasVigentes()))->resolve(),
                'limites' => $advertencias,
            ], $credito->id];
        }, estado: 201);
    }
}
