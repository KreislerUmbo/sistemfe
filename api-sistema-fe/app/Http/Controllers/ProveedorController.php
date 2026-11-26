<?php

namespace App\Http\Controllers;

use App\Models\Proveedor;
use App\Services\ProveedorDocumentoService;
use App\Services\TextoFormatoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * T-133 — proveedores del sistema base (`proveedores-base`): listar/buscar, ver, crear y editar
 * los datos básicos, en cualquier giro, con proveedores.ver / proveedores.gestionar. Lo turístico
 * (tipo, servicios, tarifas, hotel, fotos) sigue en AgenciaViajes\ProveedorController.
 *
 * No hay borrar: un proveedor se desactiva (estado = false).
 */
class ProveedorController extends Controller
{
    public function index(Request $request)
    {
        $query = Proveedor::query()->deAlcance(Proveedor::ALCANCE_TODOS);

        if ($request->filled('estado')) {
            $query->where('estado', $request->boolean('estado'));
        }

        if ($request->boolean('sin_documento')) {
            $query->sinDocumento();
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('razon_social', 'ilike', "%{$search}%")
                    ->orWhere('nombre_comercial', 'ilike', "%{$search}%")
                    ->orWhere('numero_documento', 'ilike', "%{$search}%");
            });
        }

        $perPage = min(100, max(1, (int) $request->get('per_page', 15)));
        $proveedores = $query->orderBy('razon_social')->orderBy('id')->paginate($perPage);

        return response()->json([
            'total' => $proveedores->total(),
            'paginate' => $perPage,
            'proveedores' => array_map(fn (Proveedor $p) => $this->datos($p), $proveedores->items()),
        ]);
    }

    public function show(string $id)
    {
        return response()->json(['proveedor' => $this->datos(Proveedor::findOrFail($id))]);
    }

    public function store(Request $request)
    {
        $validado = $this->validar($request, creando: true);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }
        if ($aviso = ProveedorDocumentoService::avisoDuplicado($request, $validado)) {
            return $aviso;
        }

        $proveedor = Proveedor::create($validado + ['estado' => true]);

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor registrado correctamente',
            'proveedor' => $this->datos($proveedor->refresh()),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $proveedor = Proveedor::findOrFail($id);

        $validado = $this->validar($request, creando: false, actual: $proveedor);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $proveedor->update($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor actualizado correctamente',
            'proveedor' => $this->datos($proveedor),
        ]);
    }

    private function datos(Proveedor $proveedor): array
    {
        return $proveedor->only(array_merge(['id'], Proveedor::CAMPOS_BASICOS, ['es_referencial', 'created_at']))
            + ['datos_faltantes' => $proveedor->datosFaltantes()];
    }

    // Al editar solo cambia lo que viene en la petición.
    private function validar(Request $request, bool $creando, ?Proveedor $actual = null): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'razon_social' => ($creando ? '' : 'sometimes|').'required|string|max:250',
            'nombre_comercial' => 'nullable|string|max:250',
            'tipo_persona' => 'nullable|string|max:20',
            'tipo_documento' => 'nullable|string|max:20',
            'numero_documento' => 'nullable|string|max:30',
            'direccion' => 'nullable|string',
            'direccion_fiscal' => 'nullable|string',
            'ciudad' => 'nullable|string|max:120',
            'es_no_domiciliado' => 'nullable|boolean',
            'pais' => 'nullable|string|max:80',
            'telefono' => 'nullable|string|max:50',
            'celular' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'observaciones' => 'nullable|string',
            'estado' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        if (array_key_exists('nombre_comercial', $validado)) {
            $validado['nombre_comercial'] = TextoFormatoService::capitalizarNombrePropio($validado['nombre_comercial']);
        }

        if ($error = ProveedorDocumentoService::preparar($validado, $actual)) {
            return response()->json(['code' => 422, 'message' => $error], 422);
        }

        return $validado;
    }
}
