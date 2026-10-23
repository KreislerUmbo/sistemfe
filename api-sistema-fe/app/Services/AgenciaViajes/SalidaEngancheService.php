<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;

// Enganche automático de un ítem de reserva a su Salida Operativa. Antes vivía
// como método privado de ReservaController (engancharSalidaOperativa()); se
// extrajo en la fila 13, fase 2c, para que "reprogramar una salida completa"
// (SalidaOperativaController) lo use igual que aceptar y reprogramar una
// reserva. El criterio no cambió.
//
// Enganche automático a una salida_operativa compartida — ver diseño
// completo en la migración de creación de salidas_operativas. Solo
// origen_tipo=proveedor con modalidad=compartido se engancha solo:
// proveedor_tarifas.modalidad SÍ tiene semántica compartido/privado
// real. guia_tarifas.modalidad ('dia_local'/'grupo_multidia') es un eje
// de duración de contrato, sin ninguna señal confiable de si un ítem
// de guía puntual es exclusivo de una reserva o compartible con
// otras — así que los ítems origen_tipo=guia NUNCA se auto-enganchan,
// quedan disponibles para engancharse a mano desde el tablero
// (SalidaOperativaController::attachReservaItem()). Ítems sin
// tour_origen_id tampoco se enganchan nunca (mismo motivo: sin esa
// clave no hay forma de agrupar).
//
// Fila 13, fase 2a (§3.6): la LÍNEA DE TOUR del modo nuevo también se
// engancha sola. Su modalidad sale del tour (paquetes_plantilla.modalidad):
// 'compartido' → la salida del tour y la fecha, compartida con otras
// reservas; 'privado' → una salida propia de esta reserva. Las varias
// líneas de una misma carga (IGV mezclado) caen en la misma salida porque
// comparten tour, fecha y reserva.
class SalidaEngancheService
{
    public function __construct(private SalidaServiciosService $servicios)
    {
    }

    /**
     * @return SalidaOperativa|null la salida a la que quedó enganchado (null = no corresponde)
     */
    public function enganchar(ReservaItem $reservaItem, AlternativaItem $alternativaItem, $fecha): ?SalidaOperativa
    {
        if (! $fecha || ! $alternativaItem->tour_origen_id) {
            return null;
        }

        $privadaReservaId = null;
        $tour = $alternativaItem->tourOrigen ?? PaquetePlantilla::find($alternativaItem->tour_origen_id);

        if ($alternativaItem->esLineaTour()) {
            if ($tour?->modalidad === 'privado') {
                $privadaReservaId = $reservaItem->reserva_id;
            }
        } elseif ($alternativaItem->origen_tipo !== AlternativaItem::ORIGEN_PROVEEDOR
            || $alternativaItem->proveedorTarifa?->modalidad !== 'compartido') {
            return null;
        }

        $clave = [
            'tour_origen_id' => $alternativaItem->tour_origen_id,
            'fecha' => $fecha,
            'privada_reserva_id' => $privadaReservaId,
        ];

        try {
            // Fila 13, fase 2c (22-oct): la salida nace con la hora y el lugar
            // de recojo del tour; después se cambian en la salida.
            $salida = SalidaOperativa::firstOrCreate($clave, [
                'estado' => SalidaOperativa::ESTADO_PROGRAMADA,
                'hora' => $tour?->hora_salida,
                'lugar_recojo' => $tour?->lugar_recojo ?: null,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Condición de carrera: otra request creó la misma salida entre
            // el find y el create de firstOrCreate() — el índice único
            // parcial (ver migración) la bloqueó. Recupera la que ya existe
            // en vez de tragarte el error.
            $salida = SalidaOperativa::where($clave)->first();

            if (! $salida) {
                throw $e;
            }
        }

        $reservaItem->update(['salida_operativa_id' => $salida->id]);

        // Fila 13, fase 2b (D10): con el primer tour, la salida arma su lista
        // de servicios a reservar desde el Incluye (idempotente).
        if ($alternativaItem->esLineaTour()) {
            $this->servicios->sincronizarDesdeTour($salida);
        }

        return $salida;
    }
}
