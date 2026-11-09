<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Aerolinea;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

// T-015 — Panel superadmin: administra el catálogo común de aerolíneas (las agencias solo
// buscan y agregan desde su lado). Sin borrado real: desactivar o unir, nunca eliminar — los
// nombres ya guardados en los tenants son texto y no hay FK hacia acá.
//
// Unir A en B: A queda inactiva con unida_a_id = B. Corregir el texto ya guardado en los
// tenants lo hace el comando de T-016, no esta API.
class AerolineaController extends Controller
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function index(): JsonResponse
    {
        $aerolineas = Aerolinea::orderByDesc('activa')->orderBy('nombre')->get();

        return response()->json(['aerolineas' => $aerolineas]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $aerolinea = $this->resolver($id);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:100'],
            'codigo_iata' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{2,3}$/'],
            'activa' => ['boolean'],
        ]);

        $nombre = trim(preg_replace('/\s+/u', ' ', $data['nombre']));
        $normalizado = Aerolinea::normalizar($nombre);

        // Mismo criterio que el alta de la agencia: sin duplicar el nombre normalizado.
        if (Aerolinea::where('nombre_normalizado', $normalizado)->where('id', '!=', $aerolinea->id)->exists()) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe otra aerolínea con ese nombre.']);
        }

        $activa = $data['activa'] ?? $aerolinea->activa;

        $aerolinea->update([
            'nombre' => $nombre,
            'nombre_normalizado' => $normalizado,
            'codigo_iata' => isset($data['codigo_iata']) ? strtoupper($data['codigo_iata']) : null,
            'activa' => $activa,
            // Reactivar una unida la vuelve independiente.
            'unida_a_id' => $activa ? null : $aerolinea->unida_a_id,
        ]);

        $this->auditLogger->log('aerolinea.updated', Aerolinea::class, (string) $aerolinea->id, $data);

        return response()->json(['aerolinea' => $aerolinea->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        $aerolinea = $this->resolver($id);
        $aerolinea->update(['activa' => false]);

        $this->auditLogger->log('aerolinea.deactivated', Aerolinea::class, (string) $aerolinea->id, []);

        return response()->json(['aerolinea' => $aerolinea]);
    }

    // POST aerolineas/{id}/unir {destino_id} — "id" se une en "destino_id".
    public function unir(Request $request, string $id): JsonResponse
    {
        $origen = $this->resolver($id);

        $data = $request->validate([
            'destino_id' => ['required', 'integer'],
        ]);

        if ((int) $data['destino_id'] === $origen->id) {
            throw ValidationException::withMessages(['destino_id' => 'No se puede unir una aerolínea consigo misma.']);
        }

        $destino = Aerolinea::find($data['destino_id']);
        if (! $destino || ! $destino->activa) {
            throw ValidationException::withMessages(['destino_id' => 'La aerolínea de destino no existe o está inactiva.']);
        }

        DB::connection('central')->transaction(function () use ($origen, $destino) {
            // Las que ya estaban unidas en A pasan a apuntar a B (sin cadenas).
            Aerolinea::where('unida_a_id', $origen->id)->update(['unida_a_id' => $destino->id]);
            $origen->update(['activa' => false, 'unida_a_id' => $destino->id]);
        });

        $this->auditLogger->log('aerolinea.merged', Aerolinea::class, (string) $origen->id, ['destino_id' => $destino->id]);

        return response()->json(['aerolinea' => $origen->fresh()]);
    }

    private function resolver(string $id): Aerolinea
    {
        $aerolinea = Aerolinea::find($id);

        if (! $aerolinea) {
            throw new HttpException(404, 'Aerolínea no encontrada.');
        }

        return $aerolinea;
    }
}
