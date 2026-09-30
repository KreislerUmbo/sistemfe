<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Enums\Creditos\TipoCastigo;
use App\Http\Requests\Creditos\AutorizarExcepcionRequest;
use App\Http\Requests\Creditos\ClaveRequest;
use App\Http\Requests\Creditos\CorregirCreditoRequest;
use App\Http\Requests\Creditos\MotivoRequest;
use App\Http\Resources\Creditos\CreditoResource;
use App\Services\Creditos\ActivacionService;
use App\Services\Creditos\CastigoService;
use App\Services\Creditos\CorreccionService;
use App\Services\Creditos\LimitesService;
use App\Services\Creditos\Motor\Enums\ReglaLimite;
use App\Models\Creditos\Credito;
use Illuminate\Http\JsonResponse;

/** Activar, corregir, anular, castigar/revertir y autorizar excepciones (00 1.9-1.11). */
class CreditoCicloController extends ControllerCreditos
{
    public function __construct(
        private readonly ActivacionService $activacion,
        private readonly CorreccionService $correccion,
        private readonly CastigoService $castigos,
        private readonly LimitesService $limites,
    ) {
    }

    public function activar(ClaveRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return $this->idempotente($request, 'credito.activar', fn (): array => [
            ['credito' => $this->recurso($this->activacion->activar($modelo, $this->usuario()))],
            $modelo->id,
        ], ['credito' => $credito]);
    }

    public function corregir(CorregirCreditoRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return $this->idempotente($request, 'credito.corregir', fn (): array => [
            ['credito' => $this->recurso($this->correccion->corregir($modelo, $request->aDatos($modelo->cliente_id), $request->input('motivo'), $this->usuario()))],
            $modelo->id,
        ], ['credito' => $credito]);
    }

    public function anular(MotivoRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return $this->idempotente($request, 'credito.anular', fn (): array => [
            ['credito' => $this->recurso($this->correccion->anular($modelo, $request->input('motivo'), $this->usuario()))],
            $modelo->id,
        ], ['credito' => $credito]);
    }

    public function castigar(MotivoRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return $this->idempotente($request, 'credito.castigar', function () use ($modelo, $request): array {
            $this->castigos->castigar($modelo, TipoCastigo::Manual, $request->input('motivo'), $this->usuario());

            return [['credito' => $this->recurso($modelo->refresh())], $modelo->id];
        }, ['credito' => $credito]);
    }

    public function revertirCastigo(MotivoRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return $this->idempotente($request, 'credito.revertir_castigo', function () use ($modelo, $request): array {
            $this->castigos->revertir($modelo, $request->input('motivo'), $this->usuario());

            return [['credito' => $this->recurso($modelo->refresh())], $modelo->id];
        }, ['credito' => $credito]);
    }

    public function autorizar(AutorizarExcepcionRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return $this->idempotente($request, 'credito.autorizar', function () use ($modelo, $request): array {
            $autorizacion = $this->limites->autorizar($modelo, ReglaLimite::from($request->input('regla')), $request->input('motivo'), $this->usuario());

            return [['autorizacion' => ['id' => $autorizacion->id, 'regla' => $autorizacion->regla->value, 'detalle' => $autorizacion->detalle]], $modelo->id];
        }, ['credito' => $credito], 201);
    }

    /** @return array<string, mixed> */
    private function recurso(Credito $credito): array
    {
        return (new CreditoResource($credito->load('cliente')->cargarCuotasVigentes()))->resolve();
    }
}
