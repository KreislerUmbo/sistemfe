// T-136 — pagos a proveedores.
import { enCentimos } from '@/helpers/compras';
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
export const faltaEnOrigen = (f: OrigenPagoForm): string => {
    if (f.origen === 'caja') return f.payment_method_id ? '' : 'Elige el método de pago.';
    if (!f.cuenta_bancaria_id) return 'Elige la cuenta bancaria.';
    if (!f.numero_operacion.trim()) return 'Escribe el número de operación.';
    return f.fecha ? '' : 'Indica la fecha del pago.';
};

/** Las filas con monto, listas para enviar. */
export const filasAplicadas = (montos: Record<number, string | number>) =>
    Object.entries(montos)
        .filter(([, monto]) => enCentimos(monto) > 0)
        .map(([id, monto]) => ({ compra_id: Number(id), monto: (enCentimos(monto) / 100).toFixed(2) }));

/** Lo que del pago queda sin repartir (negativo = se está repartiendo de más). */
export const quedaACuenta = (monto: string | number, montos: Record<number, string | number>): string => {
    const repartido = Object.values(montos).reduce<number>((suma, m) => suma + enCentimos(m), 0);
    return ((enCentimos(monto) - repartido) / 100).toFixed(2);
};
