<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\AfiliacionTurismo;
use App\Models\AgenciaViajes\ConfiguracionAgenciaAfiliacion;
use App\Models\AgenciaViajes\ConfiguracionAgenciaPdf;
use App\Services\AgenciaViajes\FotoUploadService;
use App\Services\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

// Pantalla "Marca del PDF" — plan-mejora-pdf-cotizacion-cliente.md §4.4.
// Singleton por tenant, mismo criterio que ConfiguracionAgenciaController
// (la migración ya insertó la fila default, GET siempre encuentra una real).
class ConfiguracionAgenciaPdfController extends Controller
{
    public function __construct(private FotoUploadService $fotoUploadService)
    {
    }

    // GET — trae la config + el catálogo completo de afiliaciones_turismo
    // (central) ya cruzado con lo que esta agencia marcó, para que el
    // frontend arme los checkboxes en un solo request.
    public function show()
    {
        $config = ConfiguracionAgenciaPdf::actual();
        $marcadas = $config->exists ? $config->afiliaciones()->get()->keyBy('afiliacion_id') : collect();

        $catalogo = AfiliacionTurismo::orderBy('nombre')->get()->map(function (AfiliacionTurismo $afiliacion) use ($marcadas) {
            $marcada = $marcadas->get($afiliacion->id);

            return [
                'id' => $afiliacion->id,
                'codigo' => $afiliacion->codigo,
                'nombre' => $afiliacion->nombre,
                'logo_url' => StorageUrl::resolve($afiliacion->logo_path),
                'marcada' => $marcada !== null,
                'numero_registro' => $marcada?->numero_registro,
            ];
        });

        return response()->json([
            'configuracion_agencia_pdf' => [
                ...$config->toArray(),
                'imagen_header_custom_url' => StorageUrl::resolve($config->imagen_header_custom),
                'imagen_footer_custom_url' => StorageUrl::resolve($config->imagen_footer_custom),
            ],
            'afiliaciones' => $catalogo,
        ]);
    }

    // PUT — reemplaza campos de branding + el set completo de afiliaciones
    // marcadas (no incremental: el frontend manda el estado final de los
    // checkboxes, mismo criterio que otros formularios de configuración
    // de una sola pantalla en este vertical).
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'color_primario' => 'nullable|string|max:7',
            'color_secundario' => 'nullable|string|max:7',
            'color_categoria_local' => 'nullable|string|max:7',
            'color_categoria_nacional' => 'nullable|string|max:7',
            'color_categoria_internacional' => 'nullable|string|max:7',
            'eslogan' => 'nullable|string|max:150',
            'redes_sociales' => 'nullable|array',
            'redes_sociales.*.red' => 'required_with:redes_sociales|string|in:facebook,instagram,tiktok',
            'redes_sociales.*.usuario' => 'required_with:redes_sociales|string|max:100',
            'mostrar_fotos_tour' => 'required|boolean',
            'mostrar_afiliaciones' => 'required|boolean',
            'afiliaciones' => 'nullable|array',
            'afiliaciones.*.afiliacion_id' => 'required|integer',
            'afiliaciones.*.numero_registro' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $v = $validator->validated();
        $afiliaciones = $v['afiliaciones'] ?? [];
        unset($v['afiliaciones']);

        // Validación real contra el catálogo central (no solo confiar en
        // los ids que manda el frontend) — mismo criterio que
        // NotaElectronicaController::validarMotivo() para cod_motivo.
        if (! empty($afiliaciones)) {
            $idsValidos = AfiliacionTurismo::whereIn('id', array_column($afiliaciones, 'afiliacion_id'))->pluck('id')->all();
            $invalidos = array_diff(array_column($afiliaciones, 'afiliacion_id'), $idsValidos);
            if (! empty($invalidos)) {
                return response()->json(['code' => 422, 'message' => 'Una o más afiliaciones seleccionadas no existen en el catálogo.'], 422);
            }
        }

        $config = DB::transaction(function () use ($v, $afiliaciones) {
            $config = ConfiguracionAgenciaPdf::first();
            $config ? $config->update($v) : $config = ConfiguracionAgenciaPdf::create($v);

            ConfiguracionAgenciaAfiliacion::where('configuracion_agencia_pdf_id', $config->id)->delete();
            foreach ($afiliaciones as $afiliacion) {
                ConfiguracionAgenciaAfiliacion::create([
                    'configuracion_agencia_pdf_id' => $config->id,
                    'afiliacion_id' => $afiliacion['afiliacion_id'],
                    'numero_registro' => $afiliacion['numero_registro'] ?? null,
                ]);
            }

            return $config;
        });

        return response()->json(['code' => 200, 'message' => 'Configuración de marca del PDF actualizada correctamente', 'configuracion_agencia_pdf' => $config]);
    }

    // POST configuracion-agencia-pdf/header|footer — override total por
    // imagen (plan §4.2). Reusa FotoUploadService (redimensiona/reorienta/
    // recomprime) — no fuerza ningún recorte de proporción, es la imagen
    // completa del membrete tal cual la suba la agencia.
    public function subirHeader(Request $request)
    {
        return $this->subirImagenCompleta($request, 'imagen_header_custom');
    }

    public function subirFooter(Request $request)
    {
        return $this->subirImagenCompleta($request, 'imagen_footer_custom');
    }

    private function subirImagenCompleta(Request $request, string $campo)
    {
        $validator = Validator::make($request->all(), [
            'imagen' => 'required|image|max:'.FotoUploadService::MAX_KB_POR_FOTO,
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $resultado = $this->fotoUploadService->procesarLote([$request->file('imagen')], 'configuracion-agencia-pdf', 0);
        if (empty($resultado['paths'])) {
            return response()->json(['code' => 422, 'message' => $resultado['rechazadas'][0]['motivo'] ?? 'No se pudo procesar la imagen.'], 422);
        }

        $config = ConfiguracionAgenciaPdf::first() ?? ConfiguracionAgenciaPdf::create();
        $anterior = $config->{$campo};
        $config->update([$campo => $resultado['paths'][0]]);

        if ($anterior && Storage::disk('public')->exists($anterior)) {
            Storage::disk('public')->delete($anterior);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Imagen actualizada correctamente',
            'url' => StorageUrl::resolve($config->{$campo}),
        ]);
    }

    public function eliminarHeader()
    {
        return $this->eliminarImagenCompleta('imagen_header_custom');
    }

    public function eliminarFooter()
    {
        return $this->eliminarImagenCompleta('imagen_footer_custom');
    }

    private function eliminarImagenCompleta(string $campo)
    {
        $config = ConfiguracionAgenciaPdf::first();
        if (! $config || ! $config->{$campo}) {
            return response()->json(['code' => 200, 'message' => 'No había ninguna imagen cargada.']);
        }

        if (Storage::disk('public')->exists($config->{$campo})) {
            Storage::disk('public')->delete($config->{$campo});
        }
        $config->update([$campo => null]);

        return response()->json(['code' => 200, 'message' => 'Imagen eliminada correctamente']);
    }
}
