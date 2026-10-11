<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Models\Client\Client;
use App\Models\Creditos\CreditoEnlaceCliente;
use App\Services\Creditos\MiCredito\EnlaceClienteService;
use Illuminate\Http\JsonResponse;

/**
 * El link "Mi crédito" de un cliente, visto desde el panel (Fase 5b): consultarlo (lo crea la
 * primera vez) y regenerarlo. Siempre dentro de la cartera de quien lo pide.
 */
class EnlaceClienteController extends ControllerCreditos
{
    public function __construct(private readonly EnlaceClienteService $enlaces)
    {
    }

    public function show(int $cliente): JsonResponse
    {
        $this->asegurarCliente($cliente);

        return response()->json($this->datos($this->enlaces->paraCliente(Client::findOrFail($cliente), $this->usuario())));
    }

    public function regenerar(int $cliente): JsonResponse
    {
        $this->asegurarCliente($cliente);

        return response()->json($this->datos($this->enlaces->regenerar(Client::findOrFail($cliente), $this->usuario())));
    }

    /** @return array<string, mixed> */
    private function datos(CreditoEnlaceCliente $enlace): array
    {
        return [
            'url' => $this->enlaces->urlDe($enlace),
            'accesos' => $enlace->accesos,
            'ultimo_acceso_en' => $enlace->ultimo_acceso_en?->toIso8601String(),
            'bloqueado_hasta' => $enlace->bloqueado() ? $enlace->bloqueado_hasta?->toIso8601String() : null,
            'regenerado_en' => $enlace->regenerado_en?->toIso8601String(),
            'creado_en' => $enlace->created_at?->toIso8601String(),
        ];
    }
}
