// T-135 — reglas de pantalla de Compras. El backend valida; esto solo ayuda a escribir.

export const TIPOS_NOTA = ['07', '08'];

/** T-154 (D4): los que pueden detallar productos. */
export const TIPOS_CON_LINEAS = ['01', '03', '04', '07', '12', '91'];

/** T-154 (D1): en estos se propone marcado «El IGV va a crédito fiscal». */
export const TIPOS_CON_CREDITO_FISCAL = ['01', '07', '08', '14'];

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

// Número escrito (hasta 6 decimales) → millonésimas, sin errores de coma flotante.
const enMillonesimas = (valor: string | number | null | undefined): bigint => {
    const numero = Number(valor ?? 0);
    return Number.isFinite(numero) ? BigInt(numero.toFixed(6).replace('.', '')) : BigInt(0);
};

/** cantidad × valor unitario al céntimo, redondeando como el backend (mitad hacia arriba). */
export const valorVentaLinea = (cantidad: string | number, valorUnitario: string | number): string => {
    const producto = enMillonesimas(cantidad) * enMillonesimas(valorUnitario); // 12 decimales
    const centimo = BigInt('10000000000');
    return (Number((producto + centimo / BigInt(2)) / centimo) / 100).toFixed(2);
};

/** Montos de una línea. Sin IGV escrito, se propone en la proporción del comprobante. */
export const montosDeLinea = (
    linea: { cantidad: string | number; valor_unitario: string | number; igv: string | number | null },
    comprobante: { base_gravada: string | number; igv: string | number },
): { valor_venta: string; igv: string; total: string } => {
    const valor = valorVentaLinea(linea.cantidad, linea.valor_unitario);
    const base = enCentimos(comprobante.base_gravada);
    const propuesto = base > 0 ? Math.round((enCentimos(valor) * enCentimos(comprobante.igv)) / base) : 0;
    const igv = linea.igv === null || linea.igv === '' ? propuesto : enCentimos(linea.igv);
    return { valor_venta: valor, igv: (igv / 100).toFixed(2), total: ((enCentimos(valor) + igv) / 100).toFixed(2) };
};
