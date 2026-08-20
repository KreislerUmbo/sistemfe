<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\PasajeroCatalogo;
use App\Models\AgenciaViajes\PasajeroDocumento;
use App\Models\AgenciaViajes\ReservaPasajero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Datos completos de un pasajero de reserva — plan-modulo-cotizaciones-reservas.md
// §4/§6.5. nombre/documento nullable desde el retrofit de Sesión 11c (el
// shell nace vacío al aceptar la alternativa) — "completo" no es una
// columna propia, el frontend lo deriva de nombre && documento, mismo
// criterio que el prototipo.
class ReservaPasajeroController extends Controller
{
    public function update(Request $request, string $id)
    {
        $pasajero = ReservaPasajero::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'nombre' => 'nullable|string|max:250',
            'documento' => 'nullable|string|max:50',
            'nacionalidad' => 'nullable|string|max:100',
            'alimentacion_especial' => 'nullable|string',
            // texto libre — permite decir QUÉ discapacidad, no solo sí/no
            // (el prototipo la muestra como checkbox por simplicidad de
            // prueba, el schema real es text nullable — ver TODO.md).
            'discapacidad' => 'nullable|string',
            'vuelo_aerolinea_ida' => 'nullable|string|max:150',
            'vuelo_fecha_ida' => 'nullable|date',
            'vuelo_hora_ida' => 'nullable|date_format:H:i',
            'vuelo_aerolinea_vuelta' => 'nullable|string|max:150',
            'vuelo_fecha_vuelta' => 'nullable|date',
            'vuelo_hora_vuelta' => 'nullable|date_format:H:i',
            'pasajero_catalogo_id' => 'nullable|integer|exists:pasajeros_catalogo,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $pasajero->update($validator->validated());

        // El buscador de catálogo (buscarCatalogo(), abajo) filtra sobre
        // pasajeros_catalogo — sin este sync esa tabla queda vacía para
        // siempre y "buscar por nombre" nunca encuentra nada (bug real
        // detectado 2026-08-17: la búsqueda no era el problema, la tabla
        // simplemente nunca se llenaba).
        if ($pasajero->nombre && $pasajero->documento) {
            $this->sincronizarCatalogo($pasajero);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Pasajero actualizado correctamente',
            'reserva_pasajero' => $pasajero->fresh(),
        ]);
    }

    // Crea o actualiza el perfil reutilizable en pasajeros_catalogo a partir
    // de un ReservaPasajero ya completo (nombre + documento). Si el mismo
    // número de documento ya existe en el catálogo (cargado desde otra
    // reserva), se reutiliza esa fila en vez de duplicarla.
    private function sincronizarCatalogo(ReservaPasajero $pasajero): void
    {
        $catalogo = $pasajero->pasajero_catalogo_id
            ? PasajeroCatalogo::find($pasajero->pasajero_catalogo_id)
            : null;

        if (!$catalogo) {
            $documentoExistente = PasajeroDocumento::where('numero_documento', $pasajero->documento)->first();
            $catalogo = $documentoExistente?->pasajeroCatalogo;
        }

        if (!$catalogo) {
            $catalogo = PasajeroCatalogo::create([
                'nombre' => $pasajero->nombre,
                'nacionalidad' => $pasajero->nacionalidad,
            ]);
            PasajeroDocumento::create([
                'pasajero_catalogo_id' => $catalogo->id,
                'tipo_documento' => 'DNI', // único tipo que maneja hoy el form de reserva_pasajeros
                'numero_documento' => $pasajero->documento,
                'fecha_registro' => now(),
            ]);
        } else {
            $catalogo->update([
                'nombre' => $pasajero->nombre,
                'nacionalidad' => $pasajero->nacionalidad ?? $catalogo->nacionalidad,
            ]);
            $documento = $catalogo->documentos()->first();
            if ($documento) {
                if ($documento->numero_documento !== $pasajero->documento) {
                    $documento->update(['numero_documento' => $pasajero->documento]);
                }
            } else {
                PasajeroDocumento::create([
                    'pasajero_catalogo_id' => $catalogo->id,
                    'tipo_documento' => 'DNI',
                    'numero_documento' => $pasajero->documento,
                    'fecha_registro' => now(),
                ]);
            }
        }

        if ($pasajero->pasajero_catalogo_id !== $catalogo->id) {
            $pasajero->update(['pasajero_catalogo_id' => $catalogo->id]);
        }
    }

    // GET pasajeros-catalogo?search= — autocompletar desde perfiles ya
    // cargados en otras reservas (Sesión 9c). Mismo criterio de debounce
    // real en el frontend (250ms) que el buscador de cliente en Ventas.
    public function buscarCatalogo(Request $request)
    {
        $search = (string) $request->get('search', '');

        if (mb_strlen($search) < 2) {
            return response()->json(['pasajeros_catalogo' => []]);
        }

        $pasajeros = PasajeroCatalogo::with('documentos')
            ->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%")
                    ->orWhereHas('documentos', fn ($qq) => $qq->where('numero_documento', 'ilike', "%{$search}%"));
            })
            ->orderBy('nombre')
            ->limit(15)
            ->get();

        return response()->json(['pasajeros_catalogo' => $pasajeros]);
    }
}
