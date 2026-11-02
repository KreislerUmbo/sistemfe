<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgenciaViajes\PenalidadPasajeroRequest;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Services\AgenciaViajes\ModificacionDiferenciaService;
use App\Services\AgenciaViajes\PenalidadPasajeroService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

// "Modificar reserva": penalidad por quitar un pasajero (T-004; R-AGE-05, D-AGE-033). Misma
// autorización que ReservaModificacionController: permiso reservas.modificar en la ruta +
// ReservaPolicy::update. La lógica vive en PenalidadPasajeroService.
class ReservaModificacionPenalidadController extends Controller
{
    public function __construct(
        private PenalidadPasajeroService $servicio,
        private ModificacionDiferenciaService $diferencia,
    ) {
    }

    // POST reservas/{id}/modificacion/penalidades
    public function store(PenalidadPasajeroRequest $request, string $id)
    {
        $modificacion = $this->modificacionAbierta($id);
        if ($modificacion instanceof JsonResponse) {
            return $modificacion;
        }

        try {
            $item = $this->servicio->crear($modificacion, $request->validated());
        } catch (HttpException $e) {
            return response()->json(['code' => $e->getStatusCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->respuesta($modificacion, $item, 'Penalidad agregada.');
    }

    // PUT reservas/{id}/modificacion/penalidades/{itemId}
    public function update(PenalidadPasajeroRequest $request, string $id, string $itemId)
    {
        $modificacion = $this->modificacionAbierta($id);
        if ($modificacion instanceof JsonResponse) {
            return $modificacion;
        }

        $item = $this->servicio->actualizar($modificacion, $this->penalidadDe($modificacion, $itemId), $request->validated());

        return $this->respuesta($modificacion, $item, 'Penalidad actualizada.');
    }

    // DELETE reservas/{id}/modificacion/penalidades/{itemId}
    public function destroy(string $id, string $itemId)
    {
        $modificacion = $this->modificacionAbierta($id);
        if ($modificacion instanceof JsonResponse) {
            return $modificacion;
        }

        $this->servicio->eliminar($modificacion, $this->penalidadDe($modificacion, $itemId));

        return response()->json(['code' => 200, 'message' => 'Penalidad borrada.'] + $this->diferencia->calcular($modificacion)->toArray());
    }

    /** La modificación abierta de la reserva, ya autorizada; o la respuesta 404. */
    private function modificacionAbierta(string $reservaId): ReservaModificacion|JsonResponse
    {
        $reserva = Reserva::findOrFail($reservaId);
        $this->authorize('update', $reserva);

        return ReservaModificacion::abierta()->where('reserva_id', $reserva->id)->first()
            ?? response()->json(['code' => 404, 'message' => 'Esta reserva no tiene una modificación abierta.'], 404);
    }

    // Solo penalidades de la versión nueva de ESTA modificación (si no, 404).
    private function penalidadDe(ReservaModificacion $modificacion, string $itemId): AlternativaItem
    {
        return AlternativaItem::where('alternativa_id', $modificacion->alternativa_nueva_id)
            ->whereNotNull('penalidad_reserva_pasajero_id')
            ->findOrFail($itemId);
    }

    private function respuesta(ReservaModificacion $modificacion, AlternativaItem $item, string $mensaje): JsonResponse
    {
        return response()->json(
            ['code' => 200, 'message' => $mensaje, 'alternativa_item' => $item] + $this->diferencia->calcular($modificacion)->toArray()
        );
    }
}
