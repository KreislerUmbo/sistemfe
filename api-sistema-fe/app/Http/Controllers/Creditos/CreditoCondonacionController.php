<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Http\Requests\Creditos\CondonarMoraRequest;
use App\Models\Creditos\CreditoCuota;
use App\Services\Creditos\CondonacionService;
use App\Services\Creditos\Dinero;
use Illuminate\Http\JsonResponse;

/** Condonar mora de una cuota (00 1.6). */
class CreditoCondonacionController extends ControllerCreditos
{
    public function __construct(private readonly CondonacionService $condonaciones)
    {
    }

    public function store(CondonarMoraRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);
        $cuota = CreditoCuota::where('credito_id', $modelo->id)->findOrFail((int) $request->input('cuota_id'));

        return $this->idempotente($request, 'mora.condonar', function () use ($modelo, $cuota, $request): array {
            $condonacion = $this->condonaciones->condonar($modelo, $cuota, Dinero::aCentavos($request->input('monto')), $request->input('motivo'), $this->usuario());

            return [['condonacion_id' => $condonacion->id, 'monto' => $condonacion->monto], $modelo->id];
        }, ['credito' => $credito], 201);
    }
}
