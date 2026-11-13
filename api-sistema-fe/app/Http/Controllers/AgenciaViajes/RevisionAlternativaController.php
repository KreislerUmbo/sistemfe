<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Concerns\AutorizaCotizacion;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Services\AgenciaViajes\RevisionAlternativaService;
use Illuminate\Http\JsonResponse;

// T-022 — GET alternativas/{id}/revision: la lista corta de "¿lista para el cliente?".
// Solo lectura; la misma regla de fila que ver la cotización (CotizacionPolicy::view).
class RevisionAlternativaController extends Controller
{
    use AutorizaCotizacion;

    public function __construct(private readonly RevisionAlternativaService $revision)
    {
    }

    public function mostrar(string $id): JsonResponse
    {
        $alternativa = Alternativa::findOrFail($id);
        $this->autorizarCotizacion('view', $alternativa);

        $revision = $this->revision->revisar($alternativa);

        return response()->json([
            'code' => 200,
            'revision' => $revision,
            'bloquea' => in_array(RevisionAlternativaService::BLOQUEA, array_column($revision, 'nivel'), true),
        ]);
    }
}
