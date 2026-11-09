<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\PasajeroCatalogo;
use App\Models\AgenciaViajes\PasajeroDocumento;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\Client\Client;
use App\Services\AgenciaViajes\PasajeroCatalogoService;
use App\Services\BusquedaLike;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Datos completos de un pasajero de reserva — plan-modulo-cotizaciones-reservas.md
// §4/§6.5. nombre/documento nullable desde el retrofit de Sesión 11c (el
// shell nace vacío al aceptar la alternativa) — "completo" no es una
// columna propia, el frontend lo deriva de nombre && documento, mismo
// criterio que el prototipo.
// T-092: ya no hay store(). Sumar un pasajero a una reserva aceptada se hace
// con «Modificar reserva», que recalcula el precio; el alta directa no lo hacía.
class ReservaPasajeroController extends Controller
{
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
            'vuelo_propio' => 'nullable|string|max:500',
            'pasajero_catalogo_id' => 'nullable|integer|exists:pasajeros_catalogo,id',
            // T-018: cliente elegido en el buscador (no es columna de reserva_pasajeros)
            'cliente_id' => 'nullable|integer|exists:clients,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $datos = $validator->validated();
        $clienteId = $datos['cliente_id'] ?? null;
        unset($datos['cliente_id']);

        // desdeCliente() bloquea la fila del cliente: va en transacción con el resto.
        DB::transaction(function () use ($pasajero, $datos, $clienteId) {
            $pasajero->update($datos);

            // T-018: si se eligió un cliente y el documento que se guarda es el suyo,
            // se resuelve su ficha (reusada o creada vinculada) ANTES de sincronizar.
            // Si el vendedor escribió a otra persona después de elegir, se ignora.
            if ($clienteId && $pasajero->documento) {
                $cliente = Client::find($clienteId);
                if ($cliente && trim((string) $cliente->n_document) === trim((string) $pasajero->documento)) {
                    $ficha = app(PasajeroCatalogoService::class)->desdeCliente($cliente);
                    if ($ficha) {
                        $pasajero->update(['pasajero_catalogo_id' => $ficha->id]);
                    }
                }
            }

            // El buscador de catálogo (buscarCatalogo(), abajo) filtra sobre
            // pasajeros_catalogo — sin este sync esa tabla queda vacía para
            // siempre y "buscar por nombre" nunca encuentra nada (bug real
            // detectado 2026-08-17: la búsqueda no era el problema, la tabla
            // simplemente nunca se llenaba).
            if ($pasajero->nombre && $pasajero->documento) {
                app(PasajeroCatalogoService::class)->sincronizarDesdeReserva($pasajero);
            }
        });

        return response()->json([
            'code' => 200,
            'message' => 'Pasajero actualizado correctamente',
            'reserva_pasajero' => $pasajero->fresh(),
        ]);
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

        $limite = 15;
        // T-092: comodines escapados (buscar «50%» no trae todo lo que tenga «50»).
        $patron = BusquedaLike::contiene($search);

        $pasajeros = PasajeroCatalogo::with('documentos')
            ->where(function ($q) use ($patron) {
                $q->where('nombre', 'ilike', $patron)
                    ->orWhereHas('documentos', fn ($qq) => $qq->where('numero_documento', 'ilike', $patron));
            })
            ->orderBy('nombre')
            ->limit($limite)
            ->get()
            ->map(function (PasajeroCatalogo $p) {
                $fila = $p->toArray();
                $fila['origen'] = 'pasajero';

                return $fila;
            });

        // T-018: también clientes persona sin ficha. Mismo criterio que
        // PasajeroCatalogoService::datosDePasajero() (DNI/CE/PAS, documento real,
        // con nombre). Un cliente ya representado por una ficha (por cliente_id o
        // por su mismo documento) no sale: esa persona sale una vez, como pasajero.
        $clientes = collect();
        $faltan = $limite - $pasajeros->count();
        if ($faltan > 0) {
            $clientes = Client::query()
                ->whereIn(DB::raw("upper(trim(coalesce(type_document, '')))"), ['DNI', 'CE', 'PAS'])
                ->whereRaw("trim(coalesce(n_document, '')) not in ('', '00000000')")
                ->whereRaw("(trim(coalesce(full_name, '')) <> '' or trim(coalesce(name, '') || ' ' || coalesce(surname, '')) <> '')")
                ->where(function ($q) use ($patron) {
                    $q->where('full_name', 'ilike', $patron)
                        ->orWhere('name', 'ilike', $patron)
                        ->orWhere('surname', 'ilike', $patron)
                        ->orWhere('n_document', 'ilike', $patron);
                })
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pasajeros_catalogo')
                    ->whereColumn('pasajeros_catalogo.cliente_id', 'clients.id'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pasajero_documentos')
                    ->whereRaw('pasajero_documentos.numero_documento = trim(clients.n_document)'))
                ->orderByRaw("coalesce(nullif(trim(full_name), ''), trim(coalesce(name, '') || ' ' || coalesce(surname, '')))")
                ->limit($faltan)
                ->get()
                ->map(function (Client $c) {
                    $datos = app(PasajeroCatalogoService::class)->datosDePasajero($c);

                    return [
                        'id' => null,
                        'cliente_id' => $c->id,
                        'origen' => 'cliente',
                        'nombre' => $datos['nombre'],
                        'nacionalidad' => null,
                        'documentos' => [[
                            'tipo_documento' => $datos['tipo_documento'],
                            'numero_documento' => $datos['documento'],
                        ]],
                    ];
                });
        }

        return response()->json(['pasajeros_catalogo' => $pasajeros->concat($clientes)->values()]);
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
