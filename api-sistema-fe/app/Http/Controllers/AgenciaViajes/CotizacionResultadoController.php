<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Concerns\AutorizaCotizacion;
use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CrmMotivoResultado;
use App\Services\AgenciaViajes\ResultadoCotizacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

// T-052 (CRM C3) — «Cerrar como perdida» y «Reabrir» desde la cabecera del cotizador. La ruta exige
// cotizaciones.editar; CotizacionPolicy::update, que sea su vendedor o tenga ver_todas. La lógica
// vive en ResultadoCotizacionService.
class CotizacionResultadoController extends Controller
{
    use AutorizaCotizacion;
    use ValidaEnEspanol;

    public function __construct(private readonly ResultadoCotizacionService $resultado)
    {
    }

    // POST cotizaciones/{id}/perdida — motivo obligatorio; detalle obligatorio solo con «Otro».
    public function cerrarComoPerdida(Request $request, string $id): JsonResponse
    {
        $cotizacion = Cotizacion::findOrFail($id);
        $this->autorizarCotizacion('update', $cotizacion);

        $validator = $this->validadorEs($request->all(), [
            'motivo_id' => ['required', 'integer', ResultadoCotizacionService::reglaMotivo(CrmMotivoResultado::TIPO_PERDIDA)],
            'detalle' => 'nullable|string|max:' . CrmMotivoResultado::DETALLE_MAX,
        ], ['motivo_id' => 'motivo', 'detalle' => 'detalle']);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $motivo = CrmMotivoResultado::findOrFail($request->integer('motivo_id'));
        $detalle = trim((string) $request->input('detalle', ''));
        if ($motivo->esOtro() && $detalle === '') {
            return response()->json([
                'code' => 422,
                'message' => 'Con «Otro» cuenta brevemente por qué se perdió.',
                'errors' => ['detalle' => ['Con «Otro» cuenta brevemente por qué se perdió.']],
            ], 422);
        }

        try {
            $this->resultado->cerrarComoPerdida($cotizacion, auth('api')->user(), $motivo, $detalle !== '' ? $detalle : null);
        } catch (HttpException $e) {
            return response()->json(['code' => $e->getStatusCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['code' => 200, 'message' => 'Cotización cerrada como perdida.']);
    }

    // POST cotizaciones/{id}/reabrir — las alternativas vuelven al estado que tenían.
    public function reabrir(string $id): JsonResponse
    {
        $cotizacion = Cotizacion::findOrFail($id);
        $this->autorizarCotizacion('update', $cotizacion);

        try {
            $this->resultado->reabrir($cotizacion, auth('api')->user());
        } catch (HttpException $e) {
            return response()->json(['code' => $e->getStatusCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['code' => 200, 'message' => 'Cotización reabierta.']);
    }
}
