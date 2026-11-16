<?php

namespace App\Services\AgenciaViajes;

use Illuminate\Support\Collection;

// Guardia tributario de la facturación de una reserva, extraído de
// ReservaFacturacionController (T-085) para que la vista previa de Venta
// directa use EXACTAMENTE el mismo criterio ("¿esto se puede facturar?") sin
// copiarlo. Trabaja sobre una lista neutral: cada elemento es un array con
//   - 'id'      : identificador de la línea (reserva_item_id o un índice),
//   - 'destino' : destino tributario efectivo ('nacional', 'amazonia',
//                 'extranjero').
// No conoce modelos ni HTTP: devuelve el bloqueo o null.
//
// T-091 (D-AGE-035, C4 y C5): gravado, exonerado (Amazonía) e inafecto van en
// el mismo comprobante, en cualquier combinación, y la Amazonía ya no se
// confirma ítem por ítem: quien factura ve y corrige la afectación de cada
// línea en la columna «Afectación» (D-AGE-037). Solo exportación sigue
// bloqueada.
class CriterioTributarioService
{
    // Exportación/extranjero sigue bloqueado sin ninguna excepción, solo o
    // mezclado: requiere validaciones legales (cliente no domiciliado, medios
    // bancarizados) que este flujo no hace.
    public const MENSAJE_TRATAMIENTO_TRIBUTARIO_EXTRANJERO = 'Esta reserva incluye servicios de exportación/extranjero. '
        . 'La facturación de estos casos está pausada — requiere validaciones legales (cliente no domiciliado, medios '
        . 'bancarizados) que este flujo todavía no hace. Contacta a soporte.';

    /**
     * @param  Collection<int, array{id: mixed, destino: string}>  $lineas
     * @return array{motivo: string, destinos: array<int, string>}|null
     *         null si todo se puede facturar junto.
     */
    public function evaluar(Collection $lineas): ?array
    {
        $destinos = $lineas->pluck('destino')->unique()->values();

        if ($destinos->contains('extranjero')) {
            return [
                'motivo' => self::MENSAJE_TRATAMIENTO_TRIBUTARIO_EXTRANJERO,
                'destinos' => $destinos->all(),
            ];
        }

        return null;
    }
}
