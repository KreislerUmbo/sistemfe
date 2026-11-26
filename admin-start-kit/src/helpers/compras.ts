// T-135 — reglas de pantalla de Compras. El backend valida; esto solo ayuda a escribir.

export const TIPOS_NOTA = ['07', '08'];

export const CAMPOS_SUMA = ['base_gravada', 'igv', 'exonerado', 'inafecto', 'otros'] as const;

/** Monto escrito → céntimos enteros (vacío o no numérico = 0). */
export const enCentimos = (valor: string | number | null | undefined): number => {
    const numero = Number(valor ?? 0);
    return Number.isFinite(numero) ? Math.round(numero * 100) : 0;
};

export const sumaDeMontos = (montos: Partial<Record<(typeof CAMPOS_SUMA)[number], string | number>>): string =>
    (CAMPOS_SUMA.reduce((total, campo) => total + enCentimos(montos[campo]), 0) / 100).toFixed(2);

/** «AAAA-MM» de una fecha «AAAA-MM-DD». */
export const periodoDe = (fecha: string): string => (fecha ?? '').slice(0, 7);

/** Aviso (no bloquea, P13): el periodo de anotación pasa de 12 meses después de la emisión. */
export const pasaDeDoceMeses = (fechaEmision: string, periodo: string): boolean => {
    const emision = periodoDe(fechaEmision);
    if (!/^\d{4}-\d{2}$/.test(emision) || !/^\d{4}-\d{2}$/.test(periodo)) return false;
    const meses = (p: string) => Number(p.slice(0, 4)) * 12 + Number(p.slice(5, 7));
    return meses(periodo) - meses(emision) > 12;
};

/** La fecha de vencimiento va en rojo si ya pasó (hoy todavía no). */
export const estaVencida = (vencimiento: string | null, hoy: string): boolean => !!vencimiento && vencimiento < hoy;

export const formatMonto = (valor: string | number, moneda: string): string =>
    `${moneda === 'USD' ? 'US$' : 'S/'} ${Number(valor).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
