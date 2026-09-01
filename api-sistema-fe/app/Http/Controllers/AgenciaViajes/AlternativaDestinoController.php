<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\OpcionMayorista;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Sesión 12f-1 (index/store) + 12f-2 (update/destroy, para los chips de
// destino del cotizador). Validación de solapamiento de fechas entre
// destinos (§23.2 de la auditoría) queda fuera a propósito — sin bloqueo,
// deuda conocida documentada en el brief de 12f-2.
class AlternativaDestinoController extends Controller
{
    public function index(string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);

        return response()->json(['alternativa_destinos' => $alternativa->destinos]);
    }

    public function store(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);

        if ($guard = $this->guardAceptada($alternativa)) {
            return $guard;
        }

        $validator = Validator::make($request->all(), [
            'destino_atractivo_id' => 'nullable|integer|exists:destinos_atractivos,id',
            'destino_texto' => 'required|string|max:250',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $siguienteOrden = (int) ($alternativa->destinos()->max('orden') ?? 0) + 1;

        $destino = AlternativaDestino::create($validado + [
            'alternativa_id' => $alternativa->id,
            'orden' => $siguienteOrden,
        ]);

        return response()->json(['code' => 200, 'message' => 'Destino agregado correctamente', 'alternativa_destino' => $destino]);
    }

    // Sesión 12f-2 — edición desde el chip activo (renombrar/editar
    // fechas). Todos los campos opcionales, actualiza solo lo que llega.
    public function update(Request $request, string $alternativaId, string $id)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $destino = AlternativaDestino::where('alternativa_id', $alternativa->id)->findOrFail($id);

        if ($guard = $this->guardAceptada($alternativa)) {
            return $guard;
        }

        $validator = Validator::make($request->all(), [
            'destino_atractivo_id' => 'nullable|integer|exists:destinos_atractivos,id',
            'destino_texto' => 'nullable|string|max:250',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $destino->update($validator->validated());

        return response()->json(['code' => 200, 'message' => 'Destino actualizado correctamente', 'alternativa_destino' => $destino->fresh()]);
    }

    // Sesión 12f-2 — borrar un chip. Nunca deja la alternativa sin
    // destinos (garantía de 12b/12c: "al menos 1 destino siempre") y
    // nunca borra en cascada contenido real — el vendedor tiene que
    // mover/borrar los ítems y opciones de mayorista primero, mismo
    // criterio que AlternativaController::destroy() con reservas.
    public function destroy(string $alternativaId, string $id)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $destino = AlternativaDestino::where('alternativa_id', $alternativa->id)->findOrFail($id);

        if ($guard = $this->guardAceptada($alternativa)) {
            return $guard;
        }

        if ($alternativa->destinos()->count() <= 1) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: es el único destino de esta alternativa.',
            ], 422);
        }

        if (AlternativaItem::where('alternativa_destino_id', $destino->id)->exists()
            || OpcionMayorista::where('alternativa_destino_id', $destino->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: este destino todavía tiene ítems u opciones de mayorista — moveelos o eliminalos primero.',
            ], 422);
        }

        $destino->delete();

        return response()->json(['code' => 200, 'message' => 'Destino eliminado correctamente']);
    }

    private function guardAceptada(Alternativa $alternativa)
    {
        // Mismo guard/mensaje ya establecido en AlternativaItemController::
        // reasignarDia()/moverBloque() para "alternativa ya aceptada".
        if ($alternativa->estado === 'aceptada') {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa ya fue aceptada y generó una reserva — usa reprogramar sobre la reserva en vez de agregar destinos acá.',
            ], 422);
        }

        return null;
    }
}
