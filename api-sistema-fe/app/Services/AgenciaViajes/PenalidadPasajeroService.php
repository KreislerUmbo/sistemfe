<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Models\AgenciaViajes\ReservaPasajero;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// "Modificar reserva" — penalidad por quitar un pasajero (T-004; R-AGE-05, R-AGE-06,
// D-AGE-033). La penalidad es un servicio manual más de la versión nueva (origen_tipo =
// manual, cantidad 1) con el monto, el motivo y el tratamiento de IGV que escribe el vendedor:
// la diferencia, aplicar y facturar la tratan como cualquier otro servicio. No hay valor por
// defecto de IGV (pendiente del contador). Se guarda como un ítem manual: el monto va en la
// moneda de la versión nueva, así que el precio convertido es el mismo monto.
class PenalidadPasajeroService
{
    public function __construct(private ModificacionDiferenciaService $diferencia)
    {
    }

    /**
     * @param  array{reserva_pasajero_id: int, monto: float|int|string, motivo: string, tip_afe_igv: string, destino_tributario: string}  $datos
     *
     * @throws HttpException 422 si el pasajero no es de esta reserva, no está quitado, está
     *                       facturado o ya tiene su penalidad
     */
    public function crear(ReservaModificacion $modificacion, array $datos): AlternativaItem
    {
        return DB::transaction(function () use ($modificacion, $datos) {
            // Lock de la reserva: dos vendedores creando la penalidad del mismo pasajero a la
            // vez no la duplican.
            $reserva = Reserva::lockForUpdate()->findOrFail($modificacion->reserva_id);

            $pasajero = ReservaPasajero::where('reserva_id', $reserva->id)->find($datos['reserva_pasajero_id']);
            if (! $pasajero) {
                abort(422, 'El pasajero no pertenece a esta reserva.');
            }

            $fila = collect($this->diferencia->calcular($modificacion)->pasajeros)
                ->firstWhere('reserva_pasajero_id', $pasajero->id);
            if (! $fila || $fila['estado'] !== 'quitado') {
                abort(422, $fila && $fila['facturado']
                    ? 'El pasajero ya está facturado: emite la nota de crédito en vez de cobrarle una penalidad.'
                    : 'Solo se puede poner penalidad a un pasajero que se quita en esta modificación.');
            }

            if (AlternativaItem::where('alternativa_id', $modificacion->alternativa_nueva_id)
                ->where('penalidad_reserva_pasajero_id', $pasajero->id)->exists()) {
                abort(422, 'Este pasajero ya tiene su penalidad.');
            }

            $alternativa = $modificacion->alternativaNueva()->firstOrFail();
            $item = AlternativaItem::create([
                'alternativa_id' => $alternativa->id,
                // Primer destino de la alternativa, como un ítem manual del cotizador
                // (AlternativaItemController::resolverAlternativaDestinoId()).
                'alternativa_destino_id' => $alternativa->destinos()->value('id'),
                'origen_tipo' => AlternativaItem::ORIGEN_MANUAL,
                'modo_precio' => 'tarifa_fija',
                'cantidad' => 1,
                'penalidad_reserva_pasajero_id' => $pasajero->id,
                'costo_snapshot' => 0,
            ] + $this->camposEditables($alternativa->moneda_cotizacion, $pasajero, $datos));

            $this->recalcularTotal($modificacion);

            return $item;
        });
    }

    /**
     * @param  array{monto: float|int|string, motivo: string, tip_afe_igv: string, destino_tributario: string}  $datos
     */
    public function actualizar(ReservaModificacion $modificacion, AlternativaItem $item, array $datos): AlternativaItem
    {
        return DB::transaction(function () use ($modificacion, $item, $datos) {
            Reserva::lockForUpdate()->findOrFail($modificacion->reserva_id);

            $pasajero = ReservaPasajero::findOrFail($item->penalidad_reserva_pasajero_id);
            $alternativa = $modificacion->alternativaNueva()->firstOrFail();
            $item->update($this->camposEditables($alternativa->moneda_cotizacion, $pasajero, $datos));

            $this->recalcularTotal($modificacion);

            return $item->fresh();
        });
    }

    public function eliminar(ReservaModificacion $modificacion, AlternativaItem $item): void
    {
        DB::transaction(function () use ($modificacion, $item) {
            Reserva::lockForUpdate()->findOrFail($modificacion->reserva_id);

            $item->delete();
            $this->recalcularTotal($modificacion);
        });
    }

    /** @return array<string, mixed> */
    private function camposEditables(string $moneda, ReservaPasajero $pasajero, array $datos): array
    {
        $monto = number_format((float) $datos['monto'], 2, '.', '');

        return [
            // El motivo viaja en el mismo texto que el nombre: no hay otra columna de descripción.
            'descripcion_manual' => 'Penalidad por cancelación — '.($pasajero->nombre ?: 'pasajero '.$this->numeroEnLaReserva($pasajero)).': '.trim($datos['motivo']),
            'moneda_costo' => $moneda,
            'precio_venta_snapshot' => $monto,
            'precio_convertido' => $monto,
            'tip_afe_igv' => $datos['tip_afe_igv'],
            'destino_tributario' => $datos['destino_tributario'],
        ];
    }

    // Sin nombre, el pasajero se nombra por su lugar en la reserva (en orden de alta), no por su
    // id: el texto llega al comprobante del cliente.
    private function numeroEnLaReserva(ReservaPasajero $pasajero): int
    {
        return ReservaPasajero::where('reserva_id', $pasajero->reserva_id)->where('id', '<=', $pasajero->id)->count();
    }

    // Mismo cálculo que AlternativaItemController::recalcularTotalAlternativa().
    private function recalcularTotal(ReservaModificacion $modificacion): void
    {
        $alternativa = $modificacion->alternativaNueva()->firstOrFail();
        $alternativa->update([
            'total' => AlternativaItem::calcularTotalEfectivo($alternativa->items()->get())['total'],
        ]);
    }
}
