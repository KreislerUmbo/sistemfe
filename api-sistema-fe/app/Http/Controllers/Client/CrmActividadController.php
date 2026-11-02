<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\Client\Client;
use App\Models\Client\CrmActividad;
use App\Services\Crm\AccionesCrm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// T-050 (CRM C1) — "Anotar contacto": llamada, WhatsApp, visita o nota con el cliente.
// Se anota desde la ficha del cliente (permiso edit_client) o desde una cotización
// (cotizaciones.editar + CotizacionPolicy::update: solo su vendedor o quien ve todas).
// T-051: también desde una reserva (reservas.editar + ReservaPolicy::update).
class CrmActividadController extends Controller
{
    use ValidaEnEspanol;

    public function __construct(private readonly AccionesCrm $acciones)
    {
    }

    // POST clients/{id}/actividades
    public function store(Request $request, string $id): JsonResponse
    {
        $cliente = Client::findOrFail($id);

        $validator = $this->validadorEs($request->all(), [
            'tipo' => 'required|in:' . implode(',', CrmActividad::TIPOS_MANUALES),
            'nota' => 'nullable|string|max:' . CrmActividad::NOTA_MAX . '|required_if:tipo,' . CrmActividad::TIPO_NOTA,
            'referencia_tipo' => 'nullable|in:' . CrmActividad::REFERENCIA_COTIZACION . ',' . CrmActividad::REFERENCIA_RESERVA,
            'referencia_id' => 'nullable|integer|required_with:referencia_tipo',
        ], ['tipo' => 'tipo de contacto', 'nota' => 'nota', 'referencia_id' => 'referencia']);
        $validator->setCustomMessages(['nota.required_if' => 'Escribe la nota.']);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $datos = $validator->validated();
        $usuario = auth('api')->user();

        if (! empty($datos['referencia_tipo'])) {
            $referencia = $this->acciones->buscarReferencia($datos['referencia_tipo'], (int) $datos['referencia_id']);
            abort_unless($referencia, 404);
            abort_unless($this->acciones->puedeEditarReferencia($usuario, $referencia), 403);

            if ((int) $this->acciones->clienteDeReferencia($referencia) !== (int) $cliente->id) {
                $cual = $referencia instanceof Cotizacion ? 'La cotización' : 'La reserva';

                return response()->json(['code' => 422, 'message' => "{$cual} no es de este cliente."], 422);
            }
        } else {
            abort_unless($usuario->can('edit_client'), 403);
        }

        $nota = trim((string) ($datos['nota'] ?? ''));

        $actividad = CrmActividad::create([
            'cliente_id' => $cliente->id,
            'referencia_tipo' => $datos['referencia_tipo'] ?? null,
            'referencia_id' => ! empty($datos['referencia_tipo']) ? $datos['referencia_id'] : null,
            'tipo' => $datos['tipo'],
            'nota' => $nota !== '' ? $nota : null,
            'usuario_id' => $usuario->id,
        ]);

        return response()->json(['code' => 200, 'message' => 'Contacto anotado.', 'actividad_id' => $actividad->id]);
    }
}
