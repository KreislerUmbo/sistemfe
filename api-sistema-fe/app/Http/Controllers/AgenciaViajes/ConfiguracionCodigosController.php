<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ConfiguracionCodigo;
use App\Services\AgenciaViajes\CodigoGeneradorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// CRUD de configuracion_codigos — plan-modulo-codigos-numeracion.md §6.2/§9,
// revisión 26-ago-2026. Mismo estilo que ConfiguracionAgenciaController
// (Validator::make plano, 422 con el primer error), pero sobre N filas (una
// por tipo) en vez de una fila singleton.
class ConfiguracionCodigosController extends Controller
{
    public function __construct(private CodigoGeneradorService $codigoGenerador)
    {
    }

    public function index()
    {
        return response()->json([
            'configuracion_codigos' => ConfiguracionCodigo::orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, string $tipo)
    {
        $config = ConfiguracionCodigo::where('tipo', $tipo)->first();

        if (!$config) {
            return response()->json(['code' => 404, 'message' => "El tipo '{$tipo}' no existe."], 404);
        }

        $validator = Validator::make($request->all(), [
            'prefijo' => 'required|string|max:20',
            'separador' => 'required|string|size:1',
            'incluye_periodo' => 'required|boolean',
            'longitud_correlativo' => 'required|integer|min:1|max:15',
            'reinicio_correlativo' => 'required|in:nunca,mensual,anual',
            'activo' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        // §6.2: reinicio_correlativo se fuerza a 'nunca' cuando el tipo no
        // incluye periodo (evita el choque "TDKM-0001" repetido cada mes/año
        // si un tipo sin periodo visible reiniciara su correlativo).
        if (!$validado['incluye_periodo']) {
            $validado['reinicio_correlativo'] = 'nunca';
        }

        // §9: reserva (deriva_de no null) es un tipo derivado — solo prefijo
        // y separador son editables, el resto de los campos del payload se
        // ignora para no fingir que tiene periodo/correlativo/reinicio
        // propios cuando en realidad hereda todo de su cotización padre.
        if ($config->deriva_de) {
            $validado = [
                'prefijo' => $validado['prefijo'],
                'separador' => $validado['separador'],
            ];
        }

        $validado['updated_by'] = $request->user()?->id;

        $config->update($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Configuración de código actualizada correctamente',
            'configuracion_codigo' => $config->fresh(),
        ]);
    }

    // ?prefijo=&separador=&incluye_periodo=&longitud_correlativo= (todos
    // opcionales) — permite previsualizar en vivo lo que el usuario está
    // editando en el formulario, sin guardarlo. Normalizado acá (no en el
    // servicio) porque llega como query string: booleanos/enteros vienen
    // como texto.
    public function previsualizar(Request $request, string $tipo)
    {
        $overrides = array_filter([
            'prefijo' => $request->query('prefijo'),
            'separador' => $request->query('separador'),
            'incluye_periodo' => $request->has('incluye_periodo') ? $request->boolean('incluye_periodo') : null,
            'longitud_correlativo' => $request->filled('longitud_correlativo') ? (int) $request->query('longitud_correlativo') : null,
        ], fn ($v) => $v !== null);

        return response()->json([
            'proximo_codigo' => $this->codigoGenerador->previsualizar($tipo, $overrides),
        ]);
    }
}
