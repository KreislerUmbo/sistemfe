<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Services\AgenciaViajes\ModificacionDiferenciaService;
use App\Services\AgenciaViajes\ModificacionReservaService;
use Symfony\Component\HttpKernel\Exception\HttpException;

// "Modificar reserva": crear, consultar y descartar la modificación abierta de una reserva
// (T-002; R-AGE-01, R-AGE-02, R-AGE-10). La lógica vive en ModificacionReservaService; acá solo
// se autoriza (permiso reservas.modificar en la ruta + ReservaPolicy::update) y se responde.
class ReservaModificacionController extends Controller
{
    public function __construct(
        private ModificacionReservaService $servicio,
        private ModificacionDiferenciaService $diferencia,
    ) {
    }

    // POST reservas/{id}/modificacion
    public function store(string $id)
    {
        $reserva = Reserva::findOrFail($id);
        $this->authorize('update', $reserva);

        try {
            $resultado = $this->servicio->crear($reserva, auth('api')->user());
        } catch (HttpException $e) {
            return response()->json(['code' => $e->getStatusCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }

        $modificacion = $resultado['modificacion'];

        return response()->json([
            'code' => 200,
            'message' => 'Modificación abierta: edítala en el cotizador.',
            'modificacion' => $modificacion,
            'cotizacion_id' => $modificacion->cotizacion_id,
            'pasajeros_edad_estimada' => $resultado['pasajeros_edad_estimada'],
            'items_recalculados' => $resultado['items_recalculados'],
            'items_para_revisar' => $resultado['items_para_revisar'],
        ]);
    }

    // GET reservas/{id}/modificacion — la abierta, o 404.
    public function show(string $id)
    {
        $reserva = Reserva::findOrFail($id);
        $this->authorize('update', $reserva);

        $modificacion = ReservaModificacion::abierta()->where('reserva_id', $reserva->id)->first();
        if (! $modificacion) {
            return response()->json(['code' => 404, 'message' => 'Esta reserva no tiene una modificación abierta.'], 404);
        }

        return response()->json([
            'code' => 200,
            'modificacion' => $modificacion,
            'cotizacion_id' => $modificacion->cotizacion_id,
        ]);
    }

    // DELETE reservas/{id}/modificacion — descarta la abierta (queda como registro).
    public function destroy(string $id)
    {
        $reserva = Reserva::findOrFail($id);
        $this->authorize('update', $reserva);

        $modificacion = ReservaModificacion::abierta()->where('reserva_id', $reserva->id)->first();
        if (! $modificacion) {
            return response()->json(['code' => 404, 'message' => 'Esta reserva no tiene una modificación abierta.'], 404);
        }

        try {
            $descartada = $this->servicio->descartar($modificacion, auth('api')->user());
        } catch (HttpException $e) {
            return response()->json(['code' => $e->getStatusCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json([
            'code' => 200,
            'message' => 'Modificación descartada. La reserva no cambió.',
            'modificacion' => $descartada,
        ]);
    }

    // GET reservas/{id}/modificacion/diferencia — qué cambia respecto de lo vendido (T-003).
    public function diferencia(string $id)
    {
        $reserva = Reserva::findOrFail($id);
        $this->authorize('update', $reserva);

        $modificacion = ReservaModificacion::abierta()->where('reserva_id', $reserva->id)->first();
        if (! $modificacion) {
            return response()->json(['code' => 404, 'message' => 'Esta reserva no tiene una modificación abierta.'], 404);
        }

        return response()->json(['code' => 200] + $this->diferencia->calcular($modificacion)->toArray());
    }

    // POST reservas/{id}/modificacion/items/{itemId}/revisado — el vendedor confirma a mano un
    // servicio "para revisar". Se pierde si el ítem o los pasajeros cambian después.
    public function marcarRevisado(string $id, string $itemId)
    {
        $reserva = Reserva::findOrFail($id);
        $this->authorize('update', $reserva);

        $modificacion = ReservaModificacion::abierta()->where('reserva_id', $reserva->id)->first();
        if (! $modificacion) {
            return response()->json(['code' => 404, 'message' => 'Esta reserva no tiene una modificación abierta.'], 404);
        }

        // Solo ítems de la versión nueva de ESTA modificación (si no, 404).
        $item = AlternativaItem::where('alternativa_id', $modificacion->alternativa_nueva_id)->findOrFail($itemId);

        $linea = collect($this->diferencia->calcular($modificacion)->lineas)->firstWhere('alternativa_item_id', $item->id);
        if (! $linea || $linea['estado'] !== 'para_revisar') {
            return response()->json(['code' => 422, 'message' => 'Este servicio no está para revisar.'], 422);
        }
        if ($linea['sin_pasajeros']) {
            return response()->json([
                'code' => 422,
                'message' => 'Este servicio era solo de pasajeros que ya no están: asígnalo a alguien o quítalo; no se puede confirmar así.',
            ], 422);
        }

        $item->update(['revisado_en_modificacion' => now()]);

        return response()->json(['code' => 200, 'message' => 'Servicio confirmado.'] + $this->diferencia->calcular($modificacion)->toArray());
    }
}
