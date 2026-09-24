<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\PasajeroCatalogo;
use App\Models\AgenciaViajes\PasajeroDocumento;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaItemVueloPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SalidaMayorista;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    // DELETE reserva-pasajeros/{id} — Fase D del plan "Proceso de reserva:
    // facturación + 3 fixes" (2026-08-19). Cubre el caso "un pasajero se
    // baja del viaje" después de aceptada la reserva.
    //
    // Auditoría de UX/funcionalidad del módulo (2026-08-27): igual que
    // ReservaItemController::destroy(), este endpoint nunca tuvo botón en
    // el frontend (reservaPasajeroService.ts no tenía eliminar()). De paso
    // se le agrega lockForUpdate() sobre la reserva — ya tenía el guard de
    // "mínimo 1", pero no cerraba la ventana de carrera con una
    // facturación concurrente (mismo lock que ya usa
    // ReservaFacturacionController::store()).
    public function destroy(string $id)
    {
        $pasajero = ReservaPasajero::with('reserva')->findOrFail($id);
        $reserva = $pasajero->reserva;

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede quitar un pasajero de una reserva activa.'], 422);
        }

        if ($reserva->pasajeros()->count() <= 1) {
            return response()->json(['code' => 422, 'message' => 'No se puede quitar: es el último pasajero de la reserva.'], 422);
        }

        // Bug real (auditoría 2026-09-23): antes chequeaba "¿esta reserva
        // tiene ALGUNA venta?" en vez de "¿ESTE pasajero específico ya está
        // en alguna venta?" — bloqueaba quitar a un pasajero nunca
        // facturado solo porque OTRO pasajero de la misma reserva ya lo
        // estaba. Contradecía el propio diseño de "facturación múltiple
        // por grupo de pasajeros" (ReservaFacturacionController.php:35-43).
        // Mismo patrón fino que ReservaItemController::destroy() —
        // pasajerosYaFacturadosIds() (ReservaFacturacionController.php:769-780)
        // ya usa este mismo criterio para el badge del frontend, el dato ya
        // existe, solo faltaba conectarlo acá.
        $yaFacturado = ReservaVenta::where('reserva_id', $reserva->id)
            ->get()
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_pasajero_ids ?? [])
            ->contains($pasajero->id);

        if ($yaFacturado) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede quitar: este pasajero ya fue facturado en una venta de esta reserva.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($pasajero, $reserva) {
                // Re-chequeo bajo lock: mismo criterio que
                // ReservaFacturacionController::store() /
                // ReservaController::actualizarFacturacionExterna().
                $reservaLocked = Reserva::where('id', $reserva->id)->lockForUpdate()->firstOrFail();

                if ($reservaLocked->pasajeros()->count() <= 1) {
                    throw new HttpException(422, 'No se puede quitar: es el último pasajero de la reserva.');
                }

                $yaFacturadoBajoLock = ReservaVenta::where('reserva_id', $reservaLocked->id)
                    ->get()
                    ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_pasajero_ids ?? [])
                    ->contains($pasajero->id);

                if ($yaFacturadoBajoLock) {
                    throw new HttpException(422, 'No se puede quitar: este pasajero ya fue facturado en una venta de esta reserva.');
                }

                // reserva_item_pasajero.reserva_pasajero_id es FK sin
                // cascadeOnDelete() — hay que limpiarla a mano antes de
                // poder borrar el pasajero.
                ReservaItemPasajero::where('reserva_pasajero_id', $pasajero->id)->delete();
                // Bug real (auditoría 2026-09-22): reserva_item_vuelo_pasajero
                // (migración 2026_08_27_110000) tampoco tiene cascadeOnDelete()
                // y quedó fuera de este limpiado desde el día que se creó —
                // borrar un pasajero con vuelo de agencia ya cargado tiraba un
                // 500 de violación de FK en vez de un mensaje claro.
                ReservaItemVueloPasajero::where('reserva_pasajero_id', $pasajero->id)->delete();
                $pasajero->delete();

                // Mismo movimiento de cupo que ReservaController::cancelar(),
                // en reversa por 1 pasajero — cupo_ocupado se contó por
                // cantidad de pasajeros al aceptar, no queda "huérfano" un
                // cupo que ya no corresponde a nadie.
                $alternativa = $reservaLocked->alternativa;
                $opcionElegida = $alternativa?->opcionMayoristaElegida;
                if ($opcionElegida && $opcionElegida->salida_mayorista_id) {
                    SalidaMayorista::where('id', $opcionElegida->salida_mayorista_id)->decrement('cupo_ocupado');
                }
            });
        } catch (HttpException $e) {
            return response()->json(['code' => $e->getStatusCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['code' => 200, 'message' => 'Pasajero quitado de la reserva correctamente.']);
    }
}
