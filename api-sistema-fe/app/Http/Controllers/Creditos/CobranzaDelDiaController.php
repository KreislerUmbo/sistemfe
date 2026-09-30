<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Models\Creditos\CreditoClienteFicha;
use App\Services\Creditos\ConsultaCreditoService;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\DetalleCredito;
use Illuminate\Http\JsonResponse;

/** Cuotas que vencen hoy y vencidas de su alcance, con el exigible (03-api). */
class CobranzaDelDiaController extends ControllerCreditos
{
    public function __construct(private readonly ConsultaCreditoService $consultas)
    {
    }

    public function index(): JsonResponse
    {
        $items = $this->consultas->cobranzaDelDia($this->usuario());
        $fichas = CreditoClienteFicha::whereIn('cliente_id', array_map(static fn (DetalleCredito $d): int => $d->credito->cliente_id, $items))
            ->get()->keyBy('cliente_id');

        return response()->json(['data' => array_map(static function (DetalleCredito $d) use ($fichas): array {
            $ficha = $fichas[$d->credito->cliente_id] ?? null;

            return [
                'credito_id' => $d->credito->id,
                'numero_credito' => $d->credito->numero_credito,
                'cliente' => [
                    'id' => $d->credito->cliente->id,
                    'nombre' => $d->credito->cliente->full_name,
                    'telefono' => $d->credito->cliente->phone,
                    'telefono_alterno' => $ficha?->telefono_alterno,
                    'direccion_cobro' => $ficha?->direccion_cobro,
                    'latitud' => $ficha?->latitud,
                    'longitud' => $ficha?->longitud,
                ],
                'exigible_hoy' => Dinero::aSoles($d->exigible),
                'mora_pendiente' => Dinero::aSoles($d->situacion?->moraPendienteTotal() ?? 0),
                'dias_atraso' => $d->diasAtraso,
            ];
        }, $items)]);
    }
}
