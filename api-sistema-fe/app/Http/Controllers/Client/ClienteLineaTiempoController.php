<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client\Client;
use App\Services\Crm\FichaClienteAgencia;
use App\Services\Tenancy\GiroActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

// T-060 (CRM C5) — la ficha del cliente como centro: resumen, «Atendido por última vez por» y
// línea de tiempo. Solo Agencia (en otro giro no hay cotizaciones ni reservas: 404). Qué se ve lo
// decide FichaClienteAgencia con los scopes de las Policy.
class ClienteLineaTiempoController extends Controller
{
    public function __construct(
        private readonly FichaClienteAgencia $ficha,
        private readonly GiroActual $giro,
    ) {
    }

    // GET clients/{id}/resumen-agencia
    public function resumen(string $id): JsonResponse
    {
        $cliente = $this->cliente($id);

        return response()->json([
            'resumen' => $this->ficha->resumen($cliente->id),
            'atendido_por' => $this->ficha->atendidoPorUltimaVez($cliente->id),
        ]);
    }

    // GET clients/{id}/linea-tiempo?tipo=cotizacion&pagina=2 — 20 por vez, más reciente primero.
    public function index(Request $request, string $id): JsonResponse
    {
        $cliente = $this->cliente($id);
        $tipo = $request->query('tipo');
        if ($tipo !== null && $tipo !== '' && ! in_array($tipo, FichaClienteAgencia::TIPOS, true)) {
            return response()->json(['code' => 422, 'message' => 'Tipo de evento no válido.'], 422);
        }

        return response()->json($this->ficha->lineaTiempo($cliente->id, $tipo ?: null, (int) $request->query('pagina', 1)));
    }

    private function cliente(string $id): Client
    {
        if (! $this->giro->es('agencia_viajes')) {
            throw new HttpException(404, 'No disponible para este giro.');
        }

        return Client::findOrFail($id);
    }
}
