<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\Client\CrmOrigenContacto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// T-059 (CRM C4) — catálogo de orígenes de contacto (Configuración de Agencia). Copia el patrón de
// CrmMotivoResultadoController (T-052). Sin destroy a propósito: un origen se desactiva, nunca se
// borra (lo referencian clientes y cotizaciones). La lectura la usan también «Nueva cotización» y
// «Nueva consulta» (permiso cotizaciones.crear en la ruta).
class CrmOrigenContactoController extends Controller
{
    use ValidaEnEspanol;

    private const ATRIBUTOS = ['nombre' => 'nombre', 'activo' => 'activo', 'orden' => 'orden'];

    // GET crm-origenes-contacto?solo_activos=1 — con el último origen activo que usó este usuario en
    // una cotización, para proponerlo por defecto.
    public function index(Request $request): JsonResponse
    {
        $origenes = CrmOrigenContacto::query()
            ->when($request->boolean('solo_activos'), fn ($q) => $q->activos())
            ->ordenados()
            ->get();

        return response()->json(['origenes' => $origenes, 'ultimo_del_usuario' => $this->ultimoDelUsuario()]);
    }

    // POST crm-origenes-contacto — el nuevo queda al final.
    public function store(Request $request): JsonResponse
    {
        $validator = $this->validadorEs($request->all(), [
            'nombre' => ['required', 'string', 'max:' . CrmOrigenContacto::NOMBRE_MAX],
        ], self::ATRIBUTOS);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $nombre = trim((string) $request->input('nombre'));
        if ($error = $this->nombreRepetido($nombre)) {
            return $error;
        }

        $origen = DB::transaction(fn () => CrmOrigenContacto::create([
            'nombre' => $nombre,
            'activo' => true,
            'orden' => (int) CrmOrigenContacto::max('orden') + 1,
        ]));

        return response()->json(['code' => 200, 'message' => 'Origen agregado.', 'origen' => $origen->fresh()]);
    }

    // PUT crm-origenes-contacto/{id} — renombrar, ordenar, activar o desactivar.
    public function update(Request $request, string $id): JsonResponse
    {
        $origen = CrmOrigenContacto::findOrFail($id);

        $validator = $this->validadorEs($request->all(), [
            'nombre' => ['sometimes', 'required', 'string', 'max:' . CrmOrigenContacto::NOMBRE_MAX],
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

        if (isset($datos['nombre']) && $datos['nombre'] !== $origen->nombre && ($error = $this->nombreRepetido($datos['nombre'], $origen->id))) {
            return $error;
        }

        $origen->update($datos);

        return response()->json(['code' => 200, 'message' => 'Origen actualizado.', 'origen' => $origen->fresh()]);
    }

    private function nombreRepetido(string $nombre, ?int $exceptoId = null): ?JsonResponse
    {
        $existe = CrmOrigenContacto::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->when($exceptoId, fn ($q) => $q->whereKeyNot($exceptoId))
            ->exists();

        if (! $existe) {
            return null;
        }

        $mensaje = 'Ya existe un origen con ese nombre.';

        return response()->json(['code' => 422, 'message' => $mensaje, 'errors' => ['nombre' => [$mensaje]]], 422);
    }

    // El origen de la cotización más reciente del usuario que tenga uno, si sigue activo. Las
    // cotizaciones son del vertical Agencia: en otro giro la tabla no existe.
    private function ultimoDelUsuario(): ?int
    {
        $usuarioId = auth('api')->id();
        if (! $usuarioId || ! Schema::hasTable('cotizaciones')) {
            return null;
        }

        $ultimo = DB::table('cotizaciones')
            ->where('vendedor_id', $usuarioId)
            ->whereNotNull('origen_contacto_id')
            ->orderByDesc('id')
            ->value('origen_contacto_id');

        return $ultimo && CrmOrigenContacto::activos()->whereKey($ultimo)->exists() ? (int) $ultimo : null;
    }
}
