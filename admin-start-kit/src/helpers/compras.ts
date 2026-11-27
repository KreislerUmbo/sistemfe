// T-135 — reglas de pantalla de Compras. El backend valida; esto solo ayuda a escribir.

import type { LineaCompraForm } from '@/types/compras';

export const TIPOS_NOTA = ['07', '08'];

/** T-154 (D4): los que pueden detallar productos. */
export const TIPOS_CON_LINEAS = ['01', '03', '04', '07', '12', '91'];

/** T-162: estos, además, admiten líneas, pero solo sin producto. */
export const TIPOS_CON_LINEAS_SIN_PRODUCTO = ['02', '05', '14'];

/** T-162: afectaciones del IGV de una línea de compra (tabla 7 de SUNAT). */
export const AFECTACIONES_COMPRA = [
    ['10', 'Gravado'],
    ['20', 'Exonerado'],
    ['30', 'Inafecto'],
    ['40', 'Exportación'],
] as const;

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

type CabeceraCompra = {
    base_gravada: string | number;
    igv: string | number;
    exonerado?: string | number;
    inafecto?: string | number;
};

/** La afectación que se propone según la cabecera. Si mezcla tipos (o no hay montos), ninguna. */
export const afectacionDeCabecera = (comprobante: CabeceraCompra): string => {
    const conMonto = ([
        ['10', comprobante.base_gravada],
        ['20', comprobante.exonerado],
        ['30', comprobante.inafecto],
    ] as const).filter(([, monto]) => enCentimos(monto) > 0);
    return conMonto.length === 1 ? conMonto[0][0] : '';
};

/** La elegida en la línea o, si no se eligió, la de la cabecera. */
export const afectacionDe = (linea: { tipo_afectacion_igv?: string }, comprobante: CabeceraCompra): string =>
    linea.tipo_afectacion_igv || afectacionDeCabecera(comprobante);

/** Montos de una línea. Sin IGV escrito, se propone en la proporción del comprobante. */
export const montosDeLinea = (
    linea: { cantidad: string | number; valor_unitario: string | number; igv: string | number | null; tipo_afectacion_igv?: string },
    comprobante: CabeceraCompra,
): { valor_venta: string; igv: string; total: string } => {
    const valor = valorVentaLinea(linea.cantidad, linea.valor_unitario);
    const base = enCentimos(comprobante.base_gravada);
    const propuesto = base > 0 ? Math.round((enCentimos(valor) * enCentimos(comprobante.igv)) / base) : 0;
    // T-162: solo una línea gravada lleva IGV.
    const afectacion = afectacionDe(linea, comprobante);
    const escrito = linea.igv === null || linea.igv === '' ? propuesto : enCentimos(linea.igv);
    const igv = afectacion && afectacion !== '10' ? 0 : escrito;
    return { valor_venta: valor, igv: (igv / 100).toFixed(2), total: ((enCentimos(valor) + igv) / 100).toFixed(2) };
};

/** Las líneas como las espera el backend (viajan como un texto JSON). */
export const lineasParaEnviar = (lineas: LineaCompraForm[], comprobante: CabeceraCompra) =>
    lineas.map((l) => ({
        product_id: l.product_id,
        descripcion: l.descripcion,
        unidad_medida: l.unidad_medida,
        codigo_proveedor: l.codigo_proveedor,
        tipo_afectacion_igv: afectacionDe(l, comprobante),
        factor: l.factor,
        cantidad: l.cantidad,
        // Vacío viaja vacío: el backend lo rechaza (un 0 bajaría el costo promedio sin aviso).
        valor_unitario: l.valor_unitario,
        ...montosDeLinea(l, comprobante),
    }));

/** El mismo cuadre del backend (D9, T-162), para avisar antes de guardar. No bloquea. */
export const avisoDeCuadre = (lineas: LineaCompraForm[], comprobante: CabeceraCompra): string => {
    const filas = lineas.map((l) => ({
        afectacion: afectacionDe(l, comprobante),
        valor: enCentimos(valorVentaLinea(l.cantidad, l.valor_unitario)),
    }));
    const texto = (centimos: number) => (centimos / 100).toFixed(2);
    const sumar = (codigos?: string[]) =>
        filas.reduce((t, f) => (!codigos || codigos.includes(f.afectacion) ? t + f.valor : t), 0);

    const cabecera = [comprobante.base_gravada, comprobante.exonerado, comprobante.inafecto].map(enCentimos);
    const base = cabecera[0] + cabecera[1] + cabecera[2];
    if (sumar() !== base) return `Las líneas suman ${texto(sumar())} y la base del comprobante es ${texto(base)}.`;
    if (filas.some((f) => !f.afectacion)) return '';

    const diferencias = [['gravado', ['10']], ['exonerado', ['20']], ['inafecto', ['30', '40']]]
        .map(([tipo, codigos], i) => {
            const suma = sumar(codigos as string[]);
            return suma === cabecera[i] ? '' : `${tipo} ${texto(suma)} en las líneas y ${texto(cabecera[i])} en el comprobante`;
        })
        .filter(Boolean);
    return diferencias.length ? `No cuadra por afectación del IGV: ${diferencias.join('; ')}.` : '';
};
