// T-136 — pagos a proveedores.
import { enCentimos, formatMonto } from '@/helpers/compras';
import { hoyPeru } from '@/helpers/fecha';
import type { EstadoDelPago, EstadoPago, OrigenPagoForm } from '@/types/pagosProveedor';

export const ESTADOS_PAGO: Record<EstadoPago, { texto: string; clase: string }> = {
    pendiente: { texto: 'Pendiente', clase: 'bg-warning text-dark' },
    parcial: { texto: 'Parcial', clase: 'bg-info' },
    pagado: { texto: 'Pagado', clase: 'bg-success' },
    vencido: { texto: 'Vencido', clase: 'bg-danger' },
};

export const ESTADOS_DEL_PAGO: Record<EstadoDelPago, { texto: string; clase: string }> = {
    pendiente_aprobacion: { texto: 'Por aprobar', clase: 'bg-warning text-dark' },
    vigente: { texto: 'Vigente', clase: 'bg-success' },
    anulado: { texto: 'Anulado', clase: 'bg-danger' },
};

/** Un saldo negativo es dinero a favor: la compra quedó pagada de más (nota de crédito). */
export const esSaldoAFavor = (saldo: string | null): boolean => enCentimos(saldo) < 0;

export const origenVacio = (): OrigenPagoForm => ({
    origen: 'caja',
    payment_method_id: null,
    cuenta_bancaria_id: null,
    numero_operacion: '',
    fecha: hoyPeru(),
});

/** Solo lo que corresponde al origen elegido. En Caja la fecha la pone el servidor. */
export const datosDeOrigen = (f: OrigenPagoForm): Record<string, string | number> => {
    if (f.origen === 'caja') {
        return { origen: 'caja', payment_method_id: f.payment_method_id ?? '' };
    }
    return {
        origen: 'banco',
        cuenta_bancaria_id: f.cuenta_bancaria_id ?? '',
        numero_operacion: f.numero_operacion.trim(),
        fecha: f.fecha ?? '',
    };
};

/** Lo que falta para poder pagar, o '' si está completo. */
export const faltaEnOrigen = (f: OrigenPagoForm, deQue = 'del pago'): string => {
    if (f.origen === 'caja') return f.payment_method_id ? '' : 'Elige el método de pago.';
    if (!f.cuenta_bancaria_id) return 'Elige la cuenta bancaria.';
    if (!f.numero_operacion.trim()) return 'Escribe el número de operación.';
    return f.fecha ? '' : `Indica la fecha ${deQue}.`;
};

/** Las filas con monto, listas para enviar. `tipos`: solo las compras en otra moneda (T-140). */
export const filasAplicadas = (montos: Record<number, string | number>, tipos: Record<number, string> = {}) =>
    Object.entries(montos)
        .filter(([, monto]) => enCentimos(monto) > 0)
        .map(([id, monto]) => ({
            compra_id: Number(id),
            monto: (enCentimos(monto) / 100).toFixed(2),
            ...(tipos[Number(id)] ? { tipo_cambio: tipos[Number(id)] } : {}),
        }));

// T-140 — vista previa de la conversión, en céntimos. El monto que vale lo calcula el servidor.
// `tc` = soles por dólar.

/** Lo que sale del pago (en su moneda) para cancelar un monto de una compra en la otra moneda. */
export const enMonedaDelPago = (centimosCompra: number, monedaCompra: 'PEN' | 'USD', tc: string | number): number => {
    const cambio = Number(tc);
    if (!(cambio > 0)) return 0;
    return Math.round(monedaCompra === 'USD' ? centimosCompra * cambio : centimosCompra / cambio);
};

/** Lo más que se puede cancelar de la compra con lo que le queda al pago, sin pasarse. */
export const enMonedaDeLaCompra = (centimosPago: number, monedaCompra: 'PEN' | 'USD', tc: string | number): number => {
    const cambio = Number(tc);
    if (!(cambio > 0)) return 0;
    return Math.floor((monedaCompra === 'USD' ? centimosPago / cambio : centimosPago * cambio) + 1e-6);
};

/** Diferencia de cambio en soles: positiva = pérdida. null = falta el tipo de cambio del pago. */
export const textoDiferencia = (diferencia: string | null | undefined): string => {
    if (diferencia === null || diferencia === undefined) return 'sin T.C.';
    const centimos = enCentimos(diferencia);
    if (centimos === 0) return formatMonto(0, 'PEN');
    return `${centimos > 0 ? 'Pérdida' : 'Ganancia'} ${formatMonto(Math.abs(centimos) / 100, 'PEN')}`;
};
