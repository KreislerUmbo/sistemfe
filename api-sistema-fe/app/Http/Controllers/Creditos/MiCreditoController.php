<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Http\Controllers\Controller;
use App\Models\Creditos\CreditoEnlaceCliente;
use App\Services\Creditos\MiCredito\EnlaceClienteService;
use App\Services\Creditos\MiCredito\MiCreditoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mi crédito" (Fase 5b): la página pública donde el cliente consulta su crédito con el link o
 * el QR, sin usuario ni contraseña. Primero los últimos 4 caracteres de su documento; el celular
 * queda recordado 90 días con una credencial firmada (cabecera X-Mi-Credito).
 * Un link que no existe, fue regenerado o está mal escrito responde siempre igual (404): no revela
 * si hay un cliente detrás.
 */
class MiCreditoController extends Controller
{
    public const CABECERA = 'X-Mi-Credito';

    public function __construct(
        private readonly EnlaceClienteService $enlaces,
        private readonly MiCreditoService $miCredito,
    ) {
    }

    public function verificar(Request $request, string $token): JsonResponse
    {
        $f = $request->validate(['ultimos4' => ['required', 'string', 'max:10']]);
        $enlace = $this->enlace($token);
        $r = $this->enlaces->verificar($enlace, $f['ultimos4']);

        return match ($r->estado) {
            'correcto' => response()->json(['credencial' => $r->credencial, 'vence' => $r->hasta?->toIso8601String()]),
            'bloqueado' => response()->json([
                'message' => 'Por seguridad, este enlace quedó bloqueado unos minutos. Intenta de nuevo más tarde.',
                'bloqueado_hasta' => $r->hasta?->toIso8601String(),
            ], 423),
            default => response()->json([
                'message' => 'Los dígitos no coinciden con el documento registrado.',
                'intentos_restantes' => $r->intentosRestantes,
            ], 422),
        };
    }

    public function show(Request $request, string $token): JsonResponse
    {
        $enlace = $this->enlace($token);
        if (! $this->enlaces->credencialValida($enlace, $request->header(self::CABECERA))) {
            return response()->json(['message' => 'Confirma tu documento para ver tu crédito.', 'verificar' => true], 401);
        }
        $this->enlaces->registrarAcceso($enlace);

        return response()->json($this->miCredito->datos($enlace->cliente));
    }

    private function enlace(string $token): CreditoEnlaceCliente
    {
        $enlace = strlen($token) <= 64 ? $this->enlaces->buscar($token) : null;
        abort_if($enlace === null || $enlace->cliente === null, 404, 'Este enlace ya no es válido. Pide uno nuevo al negocio.');

        return $enlace;
    }
}
