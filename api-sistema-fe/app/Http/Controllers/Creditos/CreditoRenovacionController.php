<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Http\Requests\Creditos\PreviewRenovacionRequest;
use App\Http\Requests\Creditos\RenovarRequest;
use App\Http\Resources\Creditos\CreditoResource;
use App\Http\Resources\Creditos\FormatoCredito;
use App\Services\Creditos\RenovacionService;
use Illuminate\Http\JsonResponse;

/** Renovación (00 1.12). */
class CreditoRenovacionController extends ControllerCreditos
{
    public function __construct(private readonly RenovacionService $renovaciones)
    {
    }

    public function preview(PreviewRenovacionRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return response()->json(FormatoCredito::renovacion(
            $this->renovaciones->preview($modelo, $request->aDatos($modelo->cliente_id), $this->usuario()),
        ));
    }

    public function store(RenovarRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return $this->idempotente($request, 'credito.renovar', function () use ($modelo, $request): array {
            $nuevo = $this->renovaciones->renovar($modelo, $request->aDatos($modelo->cliente_id), $request->input('motivo_autorizacion'), $this->usuario());

            return [['credito' => (new CreditoResource($nuevo->load('cliente')->cargarCuotasVigentes()))->resolve()], $nuevo->id];
        }, ['credito' => $credito], 201);
    }
}
