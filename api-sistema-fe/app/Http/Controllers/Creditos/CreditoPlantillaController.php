<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Services\Creditos\Documentos\DocumentoCreditoService;
use App\Services\Creditos\Documentos\PlantillaContratoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Plantilla del contrato (Plan 1.15): ver, guardar (versión nueva) y vista previa en PDF. */
class CreditoPlantillaController extends ControllerCreditos
{
    private const MAXIMO_CARACTERES = 200_000;

    public function __construct(
        private readonly PlantillaContratoService $plantillas,
        private readonly DocumentoCreditoService $documentos,
    ) {
    }

    public function show(): JsonResponse
    {
        $vigente = $this->plantillas->vigente();

        return response()->json([
            'contenido' => $vigente->contenido,
            'version' => $vigente->version,
            'actualizado' => $vigente->updated_at?->toIso8601String(),
            'variables' => collect(PlantillaContratoService::VARIABLES)->map(static fn (string $d, string $v): array => ['clave' => $v, 'descripcion' => $d])->values(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate(['contenido' => ['required', 'string', 'max:' . self::MAXIMO_CARACTERES]]);
        $nueva = $this->plantillas->guardar((string) $request->input('contenido'), $this->usuario());

        return response()->json(['contenido' => $nueva->contenido, 'version' => $nueva->version]);
    }

    /** PDF con el texto que se está editando; con credito_id usa sus datos, si no, nombres de ejemplo. */
    public function vistaPrevia(Request $request): Response
    {
        $request->validate([
            'contenido' => ['required', 'string', 'max:' . self::MAXIMO_CARACTERES],
            'credito_id' => ['nullable', 'integer'],
        ]);
        $credito = $request->filled('credito_id') ? $this->credito((int) $request->input('credito_id')) : null;

        return $this->documentos->vistaPreviaContrato((string) $request->input('contenido'), $credito);
    }
}
