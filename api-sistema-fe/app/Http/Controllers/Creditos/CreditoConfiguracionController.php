<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Http\Requests\Creditos\ConfiguracionCreditoRequest;
use App\Models\Creditos\CreditoConfiguracion;
use App\Services\Creditos\AuditoriaCredito;
use Illuminate\Http\JsonResponse;

/** Configuración del módulo (defaults de cada crédito nuevo). */
class CreditoConfiguracionController extends ControllerCreditos
{
    public function show(): JsonResponse
    {
        return response()->json(CreditoConfiguracion::actual());
    }

    public function update(ConfiguracionCreditoRequest $request, AuditoriaCredito $auditoria): JsonResponse
    {
        $config = CreditoConfiguracion::actual();
        $antes = $config->only(array_keys($request->validated()));
        $config->update([...$request->validated(), 'actualizado_por' => $this->usuario()->id]);
        $auditoria->registrar('configuracion.actualizar', $config, null, $antes, $request->validated(), null, $this->usuario());

        return response()->json($config->refresh());
    }
}
