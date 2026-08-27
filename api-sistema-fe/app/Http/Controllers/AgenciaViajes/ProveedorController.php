<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Amenidad;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorAlojamientoDetalle;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\ProveedorTipoConfig;
use App\Services\AgenciaViajes\FotoUploadService;
use App\Services\StorageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProveedorController extends Controller
{
    public function __construct(private FotoUploadService $fotoUploadService)
    {
    }

    public function index(Request $request)
    {
        $query = Proveedor::query();

        if ($request->filled('tipo_id')) {
            $query->where('tipo_id', $request->get('tipo_id'));
        }

        // Filtro opcional — default sigue mostrando todos (activos e
        // inactivos), el listado general de proveedores necesita ver los
        // inactivos para poder reactivarlos. Solo lo usan callers puntuales
        // que sí necesitan excluirlos (ej. biblioteca del cotizador).
        if ($request->filled('estado')) {
            $query->where('estado', $request->boolean('estado'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('razon_social', 'ilike', "%{$search}%")
                    ->orWhere('nombre_comercial', 'ilike', "%{$search}%");
            });
        }

        // 27-ago-2026 — un proveedor no tiene destino propio (puede operar
        // en varios, uno por cada proveedor_servicio), así que se filtra
        // por "tiene al menos un servicio en este destino o sus
        // descendientes". Mismo patrón (idsConDescendientes + whereHas)
        // que ya usa BibliotecaCotizadorController/
        // ProveedorTarifaController::biblioteca() para el mismo problema.
        if ($request->filled('destino_atractivo_id')) {
            $ids = DestinoAtractivo::idsConDescendientes((int) $request->get('destino_atractivo_id'));
            $query->whereHas('proveedorServicios.destinoServicio', fn ($q) => $q->whereIn('destino_atractivo_id', $ids));
        }

        $proveedores = $query->orderBy('razon_social')->paginate(15);

        $proveedores->getCollection()->transform(function (Proveedor $proveedor) {
            $proveedor->setAttribute('fotos', StorageUrl::resolveMuchas($proveedor->fotos ?? []));

            return $proveedor;
        });

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

        $rechazadas = [];
        if ($request->hasFile('fotos')) {
            try {
                $resultado = $this->fotoUploadService->procesarLote((array) $request->file('fotos'), 'proveedores', 0);
            } catch (ValidationException $e) {
                return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
            }
            $validado['fotos'] = $resultado['paths'];
            $rechazadas = $resultado['rechazadas'];
        }

        [$datosProveedor, $amenidadIds, $datosAlojamiento] = $this->separarSubrecursos($validado);

        $proveedor = DB::transaction(function () use ($datosProveedor, $amenidadIds, $datosAlojamiento) {
            $proveedor = Proveedor::create($datosProveedor);
            $this->sincronizarAmenidades($proveedor, $amenidadIds);
            $this->sincronizarAlojamientoDetalle($proveedor, $datosAlojamiento);

            return $proveedor;
        });

        $proveedor->load('alojamientoDetalle');
        $proveedor->setAttribute('fotos', StorageUrl::resolveMuchas($proveedor->fotos ?? []));
        $proveedor->setAttribute('amenidades', $proveedor->amenidades());

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor registrado correctamente',
            'proveedor' => $proveedor,
            'fotos_rechazadas' => $rechazadas,
        ]);
    }

    public function show(string $id)
    {
        $proveedor = Proveedor::with([
            'proveedorServicios.destinoServicio.destinoAtractivo',
            'proveedorServicios.destinoServicio.servicio',
            'alojamientoDetalle',
        ])->findOrFail($id);

        $proveedor->setAttribute('fotos', StorageUrl::resolveMuchas($proveedor->fotos ?? []));
        $proveedor->setAttribute('amenidades', $proveedor->amenidades());

        return response()->json(['proveedor' => $proveedor]);
    }

    // Mantenido pese a la consolidación de hoteles: el flujo de
    // opcion_mayorista (OpcionMayoristaController::hoteles(),
    // editar.vue::onCambiarProveedorHotel()) sigue usando este endpoint para
    // "usar tarifa registrada" al armar el hotel manual de una opción de
    // mayorista — flujo explícitamente fuera de alcance de esta sesión,
    // debe seguir funcionando exactamente igual que antes.
    public function tarifasHotel(string $id)
    {
        $proveedor = Proveedor::findOrFail($id);

        if (! $this->proveedorEsAlojamiento($proveedor)) {
            return response()->json(['code' => 422, 'message' => 'Este proveedor no es de tipo Alojamiento.'], 422);
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

        $rechazadas = [];
        if ($request->hasFile('fotos')) {
            try {
                $resultado = $this->fotoUploadService->procesarLote(
                    (array) $request->file('fotos'),
                    'proveedores',
                    count($proveedor->fotos ?? [])
                );
            } catch (ValidationException $e) {
                return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
            }
            $validado['fotos'] = array_merge($proveedor->fotos ?? [], $resultado['paths']);
            $rechazadas = $resultado['rechazadas'];
        }

        [$datosProveedor, $amenidadIds, $datosAlojamiento] = $this->separarSubrecursos($validado);

        DB::transaction(function () use ($proveedor, $datosProveedor, $amenidadIds, $datosAlojamiento) {
            $proveedor->update($datosProveedor);
            $this->sincronizarAmenidades($proveedor, $amenidadIds);
            $this->sincronizarAlojamientoDetalle($proveedor, $datosAlojamiento);
        });

        $proveedor->load('alojamientoDetalle');
        $proveedor->setAttribute('fotos', StorageUrl::resolveMuchas($proveedor->fotos ?? []));
        $proveedor->setAttribute('amenidades', $proveedor->amenidades());

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor actualizado correctamente',
            'proveedor' => $proveedor,
            'fotos_rechazadas' => $rechazadas,
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

    // amenidad_ids/hora_checkin/hora_checkout/edad_max_infante_gratis/
    // edad_max_nino_cama_adicional no son columnas de `proveedores` —
    // separados ANTES de Proveedor::create()/update() para no depender de
    // que la asignación masiva los descarte en silencio.
    private function separarSubrecursos(array $validado): array
    {
        $amenidadIds = $validado['amenidad_ids'] ?? null;
        $datosAlojamiento = [
            'hora_checkin' => $validado['hora_checkin'] ?? null,
            'hora_checkout' => $validado['hora_checkout'] ?? null,
            'edad_max_infante_gratis' => $validado['edad_max_infante_gratis'] ?? null,
            'edad_max_nino_cama_adicional' => $validado['edad_max_nino_cama_adicional'] ?? null,
        ];

        unset(
            $validado['amenidad_ids'],
            $validado['hora_checkin'],
            $validado['hora_checkout'],
            $validado['edad_max_infante_gratis'],
            $validado['edad_max_nino_cama_adicional'],
        );

        return [$validado, $amenidadIds, $datosAlojamiento];
    }

    // Sync manual (delete + insert) contra la tabla pivote — amenidad_ids es
    // null cuando el campo ni siquiera vino en el payload (no tocar lo
    // existente), array vacío cuando el usuario quitó todas las amenidades.
    private function sincronizarAmenidades(Proveedor $proveedor, ?array $amenidadIds): void
    {
        if ($amenidadIds === null) {
            return;
        }

        DB::table('proveedor_amenidad')->where('proveedor_id', $proveedor->id)->delete();

        if (empty($amenidadIds)) {
            return;
        }

        $ahora = now();
        DB::table('proveedor_amenidad')->insert(array_map(fn (int $amenidadId) => [
            'proveedor_id' => $proveedor->id,
            'amenidad_id' => $amenidadId,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], $amenidadIds));
    }

    // Solo se crea/actualiza la fila si el proveedor es de tipo Alojamiento
    // — un proveedor de otro tipo nunca debe terminar con una fila de
    // check-in/check-out sin sentido.
    private function sincronizarAlojamientoDetalle(Proveedor $proveedor, array $datosAlojamiento): void
    {
        if (! $this->proveedorEsAlojamiento($proveedor)) {
            return;
        }

        $configAgencia = ConfiguracionAgencia::first();

        ProveedorAlojamientoDetalle::updateOrCreate(
            ['proveedor_id' => $proveedor->id],
            [
                'hora_checkin' => $datosAlojamiento['hora_checkin'],
                'hora_checkout' => $datosAlojamiento['hora_checkout'],
                'edad_max_infante_gratis' => $datosAlojamiento['edad_max_infante_gratis']
                    ?? $configAgencia?->edad_max_infante_gratis_hotel_default
                    ?? 4,
                'edad_max_nino_cama_adicional' => $datosAlojamiento['edad_max_nino_cama_adicional']
                    ?? $configAgencia?->edad_max_nino_cama_adicional_hotel_default
                    ?? 12,
            ]
        );
    }

    // slug='alojamiento-hoteles' es el slug REAL del catálogo proveedor_tipos
    // en este proyecto (confirmado contra datos reales) — NO 'hotel'.
    private function proveedorEsAlojamiento(Proveedor $proveedor): bool
    {
        return (bool) ($proveedor->tipo_id
            && ProveedorTipo::where('id', $proveedor->tipo_id)->where('slug', 'alojamiento-hoteles')->exists());
    }

    private function validarPayload(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'razon_social' => 'required|string|max:250',
            'codigo' => 'nullable|string|max:20',
            'nombre_comercial' => 'nullable|string|max:250',
            'descripcion' => 'nullable|string',
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
            // Solo valida que sea una imagen válida acá — el límite de 5MB es
            // responsabilidad de FotoUploadService (mismo criterio que
            // PaquetePlantillaController).
            'fotos' => 'nullable|array',
            'fotos.*' => 'image',
            'amenidad_ids' => 'nullable|array',
            'amenidad_ids.*' => 'integer',
            // Solo aplican si el proveedor es tipo Alojamiento — validado más
            // abajo si corresponde, sin bloquear a los demás tipos.
            'hora_checkin' => 'nullable|date_format:H:i',
            'hora_checkout' => 'nullable|date_format:H:i',
            'edad_max_infante_gratis' => 'nullable|integer|min:0|max:255',
            'edad_max_nino_cama_adicional' => 'nullable|integer|min:0|max:255',
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

        // amenidad_ids referencia amenidades (central) — sin FK real cross-DB.
        if (! empty($validado['amenidad_ids'])) {
            $existentes = Amenidad::whereIn('id', $validado['amenidad_ids'])->count();
            if ($existentes !== count(array_unique($validado['amenidad_ids']))) {
                return response()->json([
                    'code' => 422,
                    'message' => 'Una o más amenidades seleccionadas no existen.',
                ], 422);
            }
        }

        return $validado;
    }
}
