<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Http\Requests\Creditos\LiquidarRequest;
use App\Http\Resources\Creditos\FormatoCredito;
use App\Http\Resources\Creditos\PagoResource;
use App\Services\Creditos\LiquidacionService;
use App\Services\Creditos\Motor\Fecha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Cotizar y confirmar la liquidación anticipada (00 1.7). */
class CreditoLiquidacionController extends ControllerCreditos
{
    public function __construct(private readonly LiquidacionService $liquidaciones)
    {
    }

    public function cotizar(Request $request, int $credito): JsonResponse
    {
        $fecha = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']])['fecha'] ?? null;

        return response()->json(FormatoCredito::liquidacion($this->liquidaciones->cotizar(
            $this->credito($credito), $fecha === null ? null : Fecha::desdeTexto($fecha), $this->usuario(),
        )));
    }

    public function liquidar(LiquidarRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return $this->idempotente($request, 'credito.liquidar', fn (): array => [
            ['pago' => (new PagoResource($this->liquidaciones->liquidar($modelo, $request->aSolicitud(), $this->usuario())->load('aplicaciones.cuota')))->resolve()],
            $modelo->id,
        ], ['credito' => $credito], 201);
    }
}
