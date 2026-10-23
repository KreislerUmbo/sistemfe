<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\PasajeroCatalogo;
use App\Models\AgenciaViajes\PasajeroDocumento;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\SalidaMayorista;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Datos completos de un pasajero de reserva — plan-modulo-cotizaciones-reservas.md
// §4/§6.5. nombre/documento nullable desde el retrofit de Sesión 11c (el
// shell nace vacío al aceptar la alternativa) — "completo" no es una
// columna propia, el frontend lo deriva de nombre && documento, mismo
// criterio que el prototipo.
class ReservaPasajeroController extends Controller
{
    // Bug real (auditoría 2026-09-23): no había ningún camino para sumar
    // un pasajero de último momento a una reserva ya aceptada — solo
    // nacían todos juntos al aceptar la alternativa
    // (ReservaController::crearReservaDesdeAlternativa()). Nace como
    // "shell" (mismo criterio que ahí: nombre/documento nullable,
    // completables después vía update()), con la opción de mandarlos de
    // una si el vendedor ya los tiene a mano.
    public function store(Request $request, string $id)
    {
        $reserva = Reserva::with('alternativa.opcionMayoristaElegida')->findOrFail($id);
        $this->authorize('update', $reserva);

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede agregar un pasajero a una reserva activa.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'tipo_pax' => 'required|in:adulto,nino,infante',
            'nombre' => 'nullable|string|max:250',
            'documento' => 'nullable|string|max:50',
            'pasajero_catalogo_id' => 'nullable|integer|exists:pasajeros_catalogo,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        [$pasajero, $alertaCupoExcedido] = DB::transaction(function () use ($reserva, $validado) {
            $reservaLocked = Reserva::where('id', $reserva->id)->lockForUpdate()->firstOrFail();

            $pasajero = ReservaPasajero::create([
                'reserva_id' => $reservaLocked->id,
                'tipo_pax' => $validado['tipo_pax'],
                'nombre' => $validado['nombre'] ?? null,
                'documento' => $validado['documento'] ?? null,
                'pasajero_catalogo_id' => $validado['pasajero_catalogo_id'] ?? null,
            ]);

            // Mismo movimiento de cupo que crearReservaDesdeAlternativa(),
            // en reversa de destroy()/ReservaController::cancelar() —
            // cupo_ocupado se cuenta por cantidad de pasajeros, no se
            // puede sumar uno sin reflejarlo ahí. No bloqueante — mismo
            // criterio que al aceptar: si excede cupo_total, se avisa, no
            // se impide (el negocio decide a mano si el mayorista puede
            // sumar uno más).
            $alertaCupoExcedido = false;
            $opcionElegida = $reservaLocked->alternativa?->opcionMayoristaElegida;
            if ($opcionElegida && $opcionElegida->salida_mayorista_id) {
                SalidaMayorista::where('id', $opcionElegida->salida_mayorista_id)->increment('cupo_ocupado');
                $salida = SalidaMayorista::find($opcionElegida->salida_mayorista_id);
                if ($salida && $salida->cupo_total !== null && $salida->cupo_ocupado > $salida->cupo_total) {
                    $alertaCupoExcedido = true;
                }
            }

            if ($pasajero->nombre && $pasajero->documento) {
                $this->sincronizarCatalogo($pasajero);
            }

            return [$pasajero, $alertaCupoExcedido];
        });

        return response()->json([
            'code' => 200,
            'message' => 'Pasajero agregado correctamente',
            'reserva_pasajero' => $pasajero->fresh(),
            'alerta_cupo_excedido' => $alertaCupoExcedido,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $pasajero = ReservaPasajero::with('reserva')->findOrFail($id);
        $this->authorize('update', $pasajero->reserva);

        if ($pasajero->reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede editar un pasajero de una reserva activa.'], 422);
        }

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
                'fecha_registro' => \App\Services\HoraPeru::hoyTexto(),
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
                    'fecha_registro' => \App\Services\HoraPeru::hoyTexto(),
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

    // DELETE reserva-pasajeros/{id} — bloqueado desde F4 etapa 1 (23-oct-2026,
    // auditoría de reserva + facturación). Quitar un pasajero sin tocar el
    // precio descuadra la reserva (en un tour, el último grupo pagaba la parte
    // del que se fue) y, según los términos y condiciones, lleva una
    // penalidad. Se hará desde "Modificar reserva" (etapa 2), que recalcula
    // con el cotizador y usa QuitarPasajeroReservaService para la limpieza.
    // La ruta se mantiene para responder un mensaje claro.
    public function destroy(string $id)
    {
        $pasajero = ReservaPasajero::with('reserva')->findOrFail($id);
        $this->authorize('update', $pasajero->reserva);

        return response()->json([
            'code' => 422,
            'message' => 'Por ahora no se puede quitar un pasajero de una reserva aceptada: cambia el precio y puede '
                .'llevar una penalidad según los términos y condiciones. Se hará desde "Modificar reserva", en construcción.',
        ], 422);
    }
}
