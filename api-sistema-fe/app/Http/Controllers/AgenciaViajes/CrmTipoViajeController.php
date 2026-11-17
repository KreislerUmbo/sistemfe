<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\CrmTipoViaje;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// T-060 (CRM C5) — catálogo de tipos de viaje (Configuración de Agencia). Copia el patrón de
// CrmOrigenContactoController (T-059). Sin destroy a propósito: un tipo se desactiva, nunca se borra
// (lo referencian los perfiles). La lectura la usan también la ficha del cliente y el cotizador.
class CrmTipoViajeController extends Controller
{
    use ValidaEnEspanol;

    private const ATRIBUTOS = ['nombre' => 'nombre', 'activo' => 'activo', 'orden' => 'orden'];

    // GET crm-tipos-viaje?solo_activos=1
    public function index(Request $request): JsonResponse
    {
        $tipos = CrmTipoViaje::query()
            ->when($request->boolean('solo_activos'), fn ($q) => $q->activos())
            ->ordenados()
            ->get();

        return response()->json(['tipos' => $tipos]);
    }

    // POST crm-tipos-viaje — el nuevo queda al final.
    public function store(Request $request): JsonResponse
    {
        $validator = $this->validadorEs($request->all(), [
            'nombre' => ['required', 'string', 'max:' . CrmTipoViaje::NOMBRE_MAX],
        ], self::ATRIBUTOS);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $nombre = trim((string) $request->input('nombre'));
        if ($error = $this->nombreRepetido($nombre)) {
            return $error;
        }

        try {
            $tipo = DB::transaction(fn () => CrmTipoViaje::create([
                'nombre' => $nombre,
                'activo' => true,
                'orden' => (int) CrmTipoViaje::max('orden') + 1,
            ]));
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e) ?? throw $e;
        }

        return response()->json(['code' => 200, 'message' => 'Tipo de viaje agregado.', 'tipo' => $tipo->fresh()]);
    }

    // PUT crm-tipos-viaje/{id} — renombrar, ordenar, activar o desactivar.
    public function update(Request $request, string $id): JsonResponse
    {
        $tipo = CrmTipoViaje::findOrFail($id);

        $validator = $this->validadorEs($request->all(), [
            'nombre' => ['sometimes', 'required', 'string', 'max:' . CrmTipoViaje::NOMBRE_MAX],
            'activo' => 'sometimes|boolean',
            'orden' => 'sometimes|integer|min:0|max:32000',
        ], self::ATRIBUTOS);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $datos = $validator->validated();
        if (array_key_exists('nombre', $datos)) {
            $datos['nombre'] = trim($datos['nombre']);
        }

        if (isset($datos['nombre']) && $datos['nombre'] !== $tipo->nombre && ($error = $this->nombreRepetido($datos['nombre'], $tipo->id))) {
            return $error;
        }

        try {
            DB::transaction(fn () => $tipo->update($datos));
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e) ?? throw $e;
        }

        return response()->json(['code' => 200, 'message' => 'Tipo de viaje actualizado.', 'tipo' => $tipo->fresh()]);
    }

    private function nombreRepetido(string $nombre, ?int $exceptoId = null): ?JsonResponse
    {
        $existe = CrmTipoViaje::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->when($exceptoId, fn ($q) => $q->whereKeyNot($exceptoId))
            ->exists();

        return $existe ? $this->respuestaNombreRepetido() : null;
    }

    // Dos altas simultáneas con el mismo nombre: el segundo choca con el índice único (23505).
    private function nombreDuplicado(QueryException $e): ?JsonResponse
    {
        if (($e->errorInfo[0] ?? null) !== '23505' || ! str_contains($e->getMessage(), 'crm_tipos_viaje_nombre_unico')) {
            return null;
        }

        return $this->respuestaNombreRepetido();
    }

    private function respuestaNombreRepetido(): JsonResponse
    {
        $mensaje = 'Ya existe un tipo de viaje con ese nombre.';

        return response()->json(['code' => 422, 'message' => $mensaje, 'errors' => ['nombre' => [$mensaje]]], 422);
    }
}
