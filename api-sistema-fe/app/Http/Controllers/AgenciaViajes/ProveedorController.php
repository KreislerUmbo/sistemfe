<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\ProveedorTipoConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProveedorController extends Controller
{
    public function index(Request $request)
    {
        $query = Proveedor::query();

        if ($request->filled('tipo_id')) {
            $query->where('tipo_id', $request->get('tipo_id'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('razon_social', 'ilike', "%{$search}%")
                    ->orWhere('nombre_comercial', 'ilike', "%{$search}%");
            });
        }

        $proveedores = $query->orderBy('razon_social')->paginate(15);

        return response()->json([
            'total' => $proveedores->total(),
            'paginate' => 15,
            'proveedores' => $proveedores->items(),
        ]);
    }

    public function store(Request $request)
    {
        $validado = $this->validarPayload($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        if ($request->hasFile('logo')) {
            $validado['logo'] = Storage::disk('public')->putFile('proveedores', $request->file('logo'));
        }

        $proveedor = Proveedor::create($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor registrado correctamente',
            'proveedor' => $proveedor,
        ]);
    }

    public function show(string $id)
    {
        $proveedor = Proveedor::with([
            'proveedorServicios.destinoServicio.destinoAtractivo',
            'proveedorServicios.destinoServicio.servicio',
        ])->findOrFail($id);

        return response()->json(['proveedor' => $proveedor]);
    }

    // Sesión 11k, Fix 9 — tarifas Hotel de este proveedor (todos sus
    // proveedor_servicios), para ofrecer "usar tarifa registrada" al armar
    // la matriz de habitaciones de un opcion_hotel.
    //
    // slug='alojamiento-hoteles' (el slug REAL del catálogo proveedor_tipos
    // en este proyecto, confirmado contra datos reales) — NO 'hotel'.
    // ProveedorTarifaController::proveedorEsHotel() usa 'hotel', que no
    // matchea ningún proveedor_tipos real (bug preexistente, encontrado al
    // verificar este endpoint contra agencia-demo — fuera de alcance de
    // esta sesión, no corregido acá, solo documentado).
    public function tarifasHotel(string $id)
    {
        $proveedor = Proveedor::findOrFail($id);

        $esHotel = $proveedor->tipo_id
            && ProveedorTipo::where('id', $proveedor->tipo_id)->where('slug', 'alojamiento-hoteles')->exists();

        if (! $esHotel) {
            return response()->json(['code' => 422, 'message' => 'Este proveedor no es de tipo Hotel.'], 422);
        }

        $tarifas = ProveedorTarifa::whereHas('proveedorServicio', fn ($q) => $q->where('proveedor_id', $proveedor->id))
            ->whereNotNull('tipo_habitacion')
            ->with('proveedorServicio.destinoServicio.servicio')
            ->orderBy('tipo_habitacion')
            ->get();

        return response()->json(['proveedor_tarifas' => $tarifas]);
    }

    public function update(Request $request, string $id)
    {
        $proveedor = Proveedor::findOrFail($id);

        $validado = $this->validarPayload($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        if ($request->hasFile('logo')) {
            if ($proveedor->logo && Storage::disk('public')->exists($proveedor->logo)) {
                Storage::disk('public')->delete($proveedor->logo);
            }
            $validado['logo'] = Storage::disk('public')->putFile('proveedores', $request->file('logo'));
        }

        $proveedor->update($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor actualizado correctamente',
            'proveedor' => $proveedor,
        ]);
    }

    public function destroy(string $id)
    {
        $proveedor = Proveedor::findOrFail($id);

        if ($proveedor->proveedorServicios()->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: el proveedor tiene servicios/tarifas asociadas. Desactívalo en su lugar (campo Estado).',
            ], 422);
        }

        $proveedor->delete();

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor eliminado correctamente',
        ]);
    }

    private function validarPayload(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'razon_social' => 'required|string|max:250',
            'codigo' => 'nullable|string|max:20',
            'nombre_comercial' => 'nullable|string|max:250',
            'tipo_persona' => 'nullable|string|max:20',
            'tipo_documento' => 'nullable|string|max:20',
            'numero_documento' => 'nullable|string|max:30',
            'direccion' => 'nullable|string',
            'telefono' => 'nullable|string|max:50',
            'celular' => 'nullable|string|max:50',
            'whatsapp' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'pagina_web' => 'nullable|string|max:250',
            'facebook' => 'nullable|string|max:250',
            'instagram' => 'nullable|string|max:250',
            'tiktok' => 'nullable|string|max:250',
            'linkedin' => 'nullable|string|max:250',
            'observaciones' => 'nullable|string',
            'estado' => 'nullable|boolean',
            'es_referencial' => 'nullable|boolean',
            'tipo_id' => 'required|integer',
            'margen_default_tipo' => 'nullable|in:porcentaje,fijo',
            'margen_default_valor' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 422,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $validado = $validator->validated();

        // tipo_id referencia proveedor_tipos (central) — sin FK real cross-DB,
        // se valida acá contra proveedor_tipos_config habilitados del tenant.
        $tipoHabilitado = ProveedorTipoConfig::where('proveedor_tipo_id', $validado['tipo_id'])
            ->where('habilitado', true)
            ->exists();

        if (! $tipoHabilitado) {
            return response()->json([
                'code' => 422,
                'message' => 'El tipo de proveedor seleccionado no está habilitado para este negocio.',
            ], 422);
        }

        return $validado;
    }
}
