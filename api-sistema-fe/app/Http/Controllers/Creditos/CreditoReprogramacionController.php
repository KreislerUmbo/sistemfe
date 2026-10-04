<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Http\Requests\Creditos\PreviewReprogramacionRequest;
use App\Http\Requests\Creditos\ReprogramarRequest;
use App\Http\Resources\Creditos\FormatoCredito;
use App\Services\Creditos\ReprogramacionService;
use Illuminate\Http\JsonResponse;

/** Reprogramar fechas con vista previa obligatoria (00 1.9). */
class CreditoReprogramacionController extends ControllerCreditos
{
    public function __construct(private readonly ReprogramacionService $reprogramaciones)
    {
    }

    public function preview(PreviewReprogramacionRequest $request, int $credito): JsonResponse
    {
        return response()->json(FormatoCredito::reprogramacion(
            $this->reprogramaciones->preview($this->credito($credito), $request->aSolicitud(), $this->usuario()),
        ));
    }

    public function store(ReprogramarRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return $this->idempotente($request, 'credito.reprogramar', function () use ($modelo, $request): array {
            $reprogramacion = $this->reprogramaciones->reprogramar($modelo, $request->aSolicitud(), $this->usuario());

            return [['reprogramacion_id' => $reprogramacion->id, 'cargo' => $reprogramacion->cargo_monto], $modelo->id];
        }, ['credito' => $credito], 201);
    }
}
