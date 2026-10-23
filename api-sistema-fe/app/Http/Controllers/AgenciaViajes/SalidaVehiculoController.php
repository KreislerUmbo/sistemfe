<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaVehiculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Vehículos de una salida — fila 13, fase 2b (§3.6, D12/D13). Varios por
// salida, cada uno con transportista (o propio), chofer, capacidad, guía
// opcional y las reservas que lleva. Pasarse de la capacidad es un aviso,
// no un bloqueo: el operador puede saber algo que el sistema no.
class SalidaVehiculoController extends Controller
{
    private const MENSAJE_CANCELADA = 'La salida está cancelada: sus vehículos ya no se modifican.';

    private function reglas(): array
    {
        return [
            'proveedor_id' => 'nullable|integer|exists:proveedores,id',
            'descripcion' => 'nullable|string|max:255',
            'chofer_nombre' => 'nullable|string|max:255',
            'chofer_telefono' => 'nullable|string|max:50',
            'capacidad' => 'nullable|integer|min:1',
            'guia_id' => 'nullable|integer|exists:guias,id',
            'notas' => 'nullable|string',
        ];
    }

    /** @return array<int, int> ids de las reservas no canceladas enganchadas a la salida */
    private function reservasActivasDe(SalidaOperativa $salida): array
    {
        return $salida->reservaItems()
            ->whereHas('reserva', fn ($q) => $q->where('estado', '!=', 'cancelada'))
            ->distinct()
            ->pluck('reserva_id')
            ->all();
    }

    // POST salidas-operativas/{id}/vehiculos
    public function store(Request $request, string $salidaId)
    {
        $salida = SalidaOperativa::findOrFail($salidaId);
        if ($salida->estado === SalidaOperativa::ESTADO_CANCELADA) {
            return response()->json(['code' => 422, 'message' => self::MENSAJE_CANCELADA], 422);
        }

        $validator = Validator::make($request->all(), $this->reglas());
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $esElPrimero = ! SalidaVehiculo::where('salida_operativa_id', $salida->id)->exists();

        $vehiculo = SalidaVehiculo::create($validator->validated() + [
            'salida_operativa_id' => $salida->id,
            'orden' => (int) SalidaVehiculo::where('salida_operativa_id', $salida->id)->max('orden') + 1,
        ]);

        // El primer vehículo lleva, de entrada, a todas las reservas de la
        // salida (lo normal es uno solo); con el segundo se reparten a mano.
        if ($esElPrimero) {
            $vehiculo->reservas()->sync($this->reservasActivasDe($salida));
        }

        return response()->json(['code' => 200, 'message' => 'Vehículo agregado.', 'vehiculo' => $vehiculo]);
    }

    // PUT salida-vehiculos/{id}
    public function update(Request $request, string $id)
    {
        $vehiculo = SalidaVehiculo::with('salida')->findOrFail($id);
        if ($vehiculo->salida->estado === SalidaOperativa::ESTADO_CANCELADA) {
            return response()->json(['code' => 422, 'message' => self::MENSAJE_CANCELADA], 422);
        }

        $reglas = array_map(fn (string $regla) => 'sometimes|'.$regla, $this->reglas());
        $validator = Validator::make($request->all(), $reglas);
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $vehiculo->update($validator->validated());

        return response()->json(['code' => 200, 'message' => 'Vehículo actualizado.', 'vehiculo' => $vehiculo->fresh()]);
    }

    // DELETE salida-vehiculos/{id} — sus reservas quedan sin vehículo.
    public function destroy(string $id)
    {
        $vehiculo = SalidaVehiculo::with('salida')->findOrFail($id);
        if ($vehiculo->salida->estado === SalidaOperativa::ESTADO_CANCELADA) {
            return response()->json(['code' => 422, 'message' => self::MENSAJE_CANCELADA], 422);
        }

        $vehiculo->delete();

        return response()->json(['code' => 200, 'message' => 'Vehículo quitado. Sus reservas quedaron sin vehículo.']);
    }

    // PUT salida-vehiculos/{id}/reservas — qué reservas lleva este vehículo.
    // Una reserva va en un solo vehículo de la salida: asignarla acá la saca
    // del otro.
    public function asignarReservas(Request $request, string $id)
    {
        $vehiculo = SalidaVehiculo::with('salida.reservaItems')->findOrFail($id);
        $salida = $vehiculo->salida;
        if ($salida->estado === SalidaOperativa::ESTADO_CANCELADA) {
            return response()->json(['code' => 422, 'message' => self::MENSAJE_CANCELADA], 422);
        }

        $validator = Validator::make($request->all(), [
            'reserva_ids' => 'present|array',
            'reserva_ids.*' => 'integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $reservaIds = collect($request->get('reserva_ids'))->map(fn ($r) => (int) $r)->unique()->values();
        $deLaSalida = $salida->reservaItems->pluck('reserva_id')->unique();

        $ajenas = $reservaIds->diff($deLaSalida);
        if ($ajenas->isNotEmpty()) {
            return response()->json(['code' => 422, 'message' => 'Estas reservas no están en la salida: '.$ajenas->implode(', ')], 422);
        }

        DB::transaction(function () use ($vehiculo, $salida, $reservaIds) {
            $otrosVehiculos = SalidaVehiculo::where('salida_operativa_id', $salida->id)->where('id', '!=', $vehiculo->id)->pluck('id');
            DB::table('salida_vehiculo_reservas')
                ->whereIn('salida_vehiculo_id', $otrosVehiculos)
                ->whereIn('reserva_id', $reservaIds)
                ->delete();

            $vehiculo->reservas()->sync($reservaIds->all());
        });

        // Aviso, no bloqueo: pasajeros que ocupan asiento (sin infantes).
        $pax = Reserva::whereIn('id', $reservaIds)
            ->withCount(['pasajeros as ocupan_count' => fn ($q) => $q->where('tipo_pax', '!=', 'infante')])
            ->get()
            ->sum('ocupan_count');
        $excede = $vehiculo->capacidad !== null && $pax > $vehiculo->capacidad;

        return response()->json([
            'code' => 200,
            'message' => $excede
                ? "Reservas asignadas. Ojo: van {$pax} pasajeros y la capacidad es {$vehiculo->capacidad}."
                : 'Reservas asignadas al vehículo.',
            'pasajeros' => $pax,
            'excede_capacidad' => $excede,
        ]);
    }
}
