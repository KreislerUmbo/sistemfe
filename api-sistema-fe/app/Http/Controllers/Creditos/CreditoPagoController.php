<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Http\Requests\Creditos\CobrarRequest;
use App\Http\Requests\Creditos\CotizarPagoRequest;
use App\Http\Requests\Creditos\EditarPagoRequest;
use App\Http\Requests\Creditos\MotivoRequest;
use App\Http\Resources\Creditos\FormatoCredito;
use App\Http\Resources\Creditos\PagoResource;
use App\Models\Creditos\CreditoPago;
use App\Services\Creditos\AnulacionPagoService;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use Illuminate\Http\JsonResponse;

/** Cotizar, cobrar (incl. retroactivo), editar referencia y anular pagos (00 1.4-1.8, 1.12). */
class CreditoPagoController extends ControllerCreditos
{
    public function __construct(
        private readonly CobroService $cobros,
        private readonly AnulacionPagoService $anulaciones,
    ) {
    }

    public function cotizar(CotizarPagoRequest $request, int $credito): JsonResponse
    {
        $cotizacion = $this->cobros->cotizar(
            $this->credito($credito),
            Dinero::aCentavos($request->input('monto_recibido')),
            DestinoExcedente::tryFrom((string) $request->input('destino_excedente')) ?? DestinoExcedente::Devolver,
            $this->usuario(),
        );

        return response()->json(FormatoCredito::cotizacion($cotizacion));
    }

    public function store(CobrarRequest $request, int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return $this->idempotente($request, 'pago.cobrar', fn (): array => [
            ['pago' => $this->recurso($this->cobros->cobrar($modelo, $request->aSolicitud(), $this->usuario()))],
            $modelo->id,
        ], ['credito' => $credito], 201);
    }

    public function update(EditarPagoRequest $request, int $credito, int $pago): JsonResponse
    {
        $modelo = $this->pago($credito, $pago);

        return response()->json(['pago' => $this->recurso(
            $this->anulaciones->editar($modelo, $request->input('referencia'), $request->input('observaciones'), $this->usuario()),
        )]);
    }

    public function anular(MotivoRequest $request, int $credito, int $pago): JsonResponse
    {
        $modelo = $this->pago($credito, $pago);

        return $this->idempotente($request, 'pago.anular', fn (): array => [
            ['pago' => $this->recurso($this->anulaciones->anular($modelo, $request->input('motivo'), $this->usuario()))],
            $credito,
        ], ['credito' => $credito, 'pago' => $pago]);
    }

    private function pago(int $credito, int $pago): CreditoPago
    {
        $this->credito($credito);

        return CreditoPago::where('credito_id', $credito)->findOrFail($pago);
    }

    /** @return array<string, mixed> */
    private function recurso(CreditoPago $pago): array
    {
        return (new PagoResource($pago->load('aplicaciones.cuota')))->resolve();
    }
}
