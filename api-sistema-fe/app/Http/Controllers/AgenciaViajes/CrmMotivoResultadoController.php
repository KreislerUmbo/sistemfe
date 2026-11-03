<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\CrmMotivoResultado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// T-052 (CRM C3) — catálogo de motivos de venta ganada / perdida (Configuración de Agencia). Sin
// destroy a propósito: un motivo se desactiva, nunca se borra (lo referencian las cotizaciones).
// La lectura la usan también los modales del cotizador (permiso cotizaciones.editar en la ruta).
class CrmMotivoResultadoController extends Controller
{
    use ValidaEnEspanol;

    private const ATRIBUTOS = ['tipo' => 'tipo', 'nombre' => 'nombre', 'activo' => 'activo', 'orden' => 'orden'];

    // GET crm-motivos-resultado?tipo=perdida&solo_activos=1
    public function index(Request $request): JsonResponse
    {
        $motivos = CrmMotivoResultado::query()
            ->when(in_array($request->query('tipo'), CrmMotivoResultado::TIPOS, true), fn ($q) => $q->where('tipo', $request->query('tipo')))
            ->when($request->boolean('solo_activos'), fn ($q) => $q->activos())
            ->ordenados()
            ->get();

        return response()->json(['motivos' => $motivos]);
    }

    // POST crm-motivos-resultado — el nuevo queda justo antes de «Otro», que sigue último.
    public function store(Request $request): JsonResponse
    {
        $validator = $this->validadorEs($request->all(), [
            'tipo' => ['required', Rule::in(CrmMotivoResultado::TIPOS)],
            'nombre' => ['required', 'string', 'max:' . CrmMotivoResultado::NOMBRE_MAX],
        ], self::ATRIBUTOS);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $tipo = $request->input('tipo');
        $nombre = trim((string) $request->input('nombre'));
        if ($error = $this->nombreRepetido($tipo, $nombre)) {
            return $error;
        }

        $motivo = DB::transaction(function () use ($tipo, $nombre) {
            $otro = CrmMotivoResultado::where('tipo', $tipo)->where('nombre', CrmMotivoResultado::NOMBRE_OTRO)->first();
            $orden = $otro?->orden ?? ((int) CrmMotivoResultado::where('tipo', $tipo)->max('orden') + 1);
            $otro?->update(['orden' => $orden + 1]);

            return CrmMotivoResultado::create(['tipo' => $tipo, 'nombre' => $nombre, 'activo' => true, 'orden' => $orden]);
        });

        return response()->json(['code' => 200, 'message' => 'Motivo agregado.', 'motivo' => $motivo->fresh()]);
    }

    // PUT crm-motivos-resultado/{id} — renombrar, ordenar, activar o desactivar. «Otro» solo se ordena.
    public function update(Request $request, string $id): JsonResponse
    {
        $motivo = CrmMotivoResultado::findOrFail($id);

        $validator = $this->validadorEs($request->all(), [
            'nombre' => ['sometimes', 'required', 'string', 'max:' . CrmMotivoResultado::NOMBRE_MAX],
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

        if ($motivo->esOtro()
            && ((isset($datos['nombre']) && $datos['nombre'] !== $motivo->nombre) || (array_key_exists('activo', $datos) && ! $datos['activo']))) {
            return response()->json(['code' => 422, 'message' => '«Otro» no se renombra ni se desactiva: es la opción para lo que no está en la lista.'], 422);
        }

        if (isset($datos['nombre']) && $datos['nombre'] !== $motivo->nombre && ($error = $this->nombreRepetido($motivo->tipo, $datos['nombre'], $motivo->id))) {
            return $error;
        }

        $motivo->update($datos);

        return response()->json(['code' => 200, 'message' => 'Motivo actualizado.', 'motivo' => $motivo->fresh()]);
    }

    private function nombreRepetido(string $tipo, string $nombre, ?int $exceptoId = null): ?JsonResponse
    {
        $existe = CrmMotivoResultado::where('tipo', $tipo)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->when($exceptoId, fn ($q) => $q->whereKeyNot($exceptoId))
            ->exists();

        if (! $existe) {
            return null;
        }

        $mensaje = 'Ya existe un motivo con ese nombre.';

        return response()->json(['code' => 422, 'message' => $mensaje, 'errors' => ['nombre' => [$mensaje]]], 422);
    }
}
