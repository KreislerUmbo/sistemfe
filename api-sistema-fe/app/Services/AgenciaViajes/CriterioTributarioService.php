<?php

namespace App\Services\AgenciaViajes;

use Illuminate\Support\Collection;

// Guardia tributario de la facturación de una reserva, extraído de
// ReservaFacturacionController (T-085) para que la vista previa de Venta
// directa use EXACTAMENTE el mismo criterio ("¿esto se puede facturar en un
// solo comprobante?") sin copiarlo. Trabaja sobre una lista neutral: cada
// elemento es un array con
//   - 'id'          : identificador de la línea (reserva_item_id o un índice),
//   - 'destino'     : destino tributario efectivo ('nacional', 'amazonia',
//                     'extranjero'),
//   - 'carga_tour'  : código de carga del tour o null,
//   - 'confirmado'  : true si un humano confirmó la línea Amazonía
//                     (motivo_override_tributario no nulo).
// No conoce modelos ni HTTP: devuelve el bloqueo o null.
class CriterioTributarioService
{
    public const MENSAJE_MEZCLA_TRIBUTARIA = 'La reserva combina servicios con tratamiento tributario '
        . 'distinto (ej. exonerado Amazonía + gravado nacional). Solo un mismo tour puede llevar los dos '
        . 'en un comprobante; factura estos servicios en comprobantes separados o revísalo con el contador.';

    // Exportación/extranjero sigue bloqueado sin ninguna excepción: requiere
    // validaciones legales (cliente no domiciliado, medios bancarizados) que
    // este flujo no hace.
    public const MENSAJE_TRATAMIENTO_TRIBUTARIO_EXTRANJERO = 'Esta reserva incluye servicios de exportación/extranjero. '
        . 'La facturación de estos casos está pausada — requiere validaciones legales (cliente no domiciliado, medios '
        . 'bancarizados) que este flujo todavía no hace. Contacta a soporte.';

    // Un servicio de la Amazonía (Ley 27037) nunca se factura solo porque
    // destino_tributario llegó así copiado de la tarifa: un humano lo confirma
    // ítem por ítem primero (ReservaItem::motivo_override_tributario).
    public const MENSAJE_AMAZONIA_SIN_CONFIRMAR = 'Esta reserva incluye servicios de la Amazonía (exonerados de IGV '
        . 'por Ley 27037), pero todavía no fueron confirmados uno por uno. Confirmá el tratamiento tributario de cada '
        . 'servicio antes de poder facturarlos.';

    /**
     * @param  Collection<int, array{id: mixed, destino: string, carga_tour: ?string, confirmado: bool}>  $lineas
     * @return array{motivo: string, destinos: array<int, string>, sin_confirmar_ids?: array<int, mixed>}|null
     *         null si todo se puede facturar junto.
     */
    public function evaluar(Collection $lineas): ?array
    {
        $destinos = $lineas->pluck('destino')->unique()->values();

        // 'extranjero' sigue bloqueado, solo o mezclado con otro destino.
        if ($destinos->contains('extranjero')) {
            return [
                'motivo' => $destinos->count() > 1 ? self::MENSAJE_MEZCLA_TRIBUTARIA : self::MENSAJE_TRATAMIENTO_TRIBUTARIO_EXTRANJERO,
                'destinos' => $destinos->all(),
            ];
        }

        if ($destinos->count() > 1 && ! $this->mezclaDentroDeUnaCargaTour($lineas)) {
            return [
                'motivo' => self::MENSAJE_MEZCLA_TRIBUTARIA,
                'destinos' => $destinos->all(),
            ];
        }

        // 'amazonia' se puede facturar SOLO si cada línea Amazonía ya fue
        // confirmada a mano. En un tour con IGV mezclado también se confirma
        // cada línea Amazonía de la carga.
        $sinConfirmar = $lineas
            ->filter(fn (array $l) => $l['destino'] === 'amazonia' && ! $l['confirmado'])
            ->values();

        if ($sinConfirmar->isNotEmpty()) {
            return [
                'motivo' => self::MENSAJE_AMAZONIA_SIN_CONFIRMAR,
                'destinos' => $destinos->all(),
                'sin_confirmar_ids' => $sinConfirmar->pluck('id')->values()->all(),
            ];
        }

        return null;
    }

    // Fila 13, 1c (D1 y D5): un tour cuyo Incluye mezcla Amazonía (exonerada)
    // y fuera de ella (gravada) se carga como varias líneas con la misma
    // carga_tour, y SUNAT exige cada concepto con su afectación. Esas líneas sí
    // pueden ir juntas. La mezcla se permite solo si:
    // - exactamente UNA carga_tour tiene líneas de más de un destino, y
    // - todo lo demás del comprobante comparte un solo destino.
    // Dos cargas mezcladas, o servicios sueltos de distinto destino, siguen
    // bloqueados. Un combo no cuenta como una sola carga: cada tour del combo
    // es su propia carga_tour.
    private function mezclaDentroDeUnaCargaTour(Collection $lineas): bool
    {
        $cargasMezcladas = $lineas
            ->filter(fn (array $l) => $l['carga_tour'] !== null)
            ->groupBy('carga_tour')
            ->filter(fn (Collection $de) => $de->pluck('destino')->unique()->count() > 1);

        if ($cargasMezcladas->count() !== 1) {
            return false;
        }

        $cargaMezclada = $cargasMezcladas->keys()->first();

        return $lineas
            ->reject(fn (array $l) => $l['carga_tour'] === $cargaMezclada)
            ->pluck('destino')
            ->unique()
            ->count() <= 1;
    }
}
