// Análisis de impuestos, Agencia de Viajes (28-ago-2026) — a diferencia del
// módulo de Productos/Ventas (resolverTipAfeIgv.ts), acá el precio que
// escribe el vendedor SIEMPRE es el precio FINAL, todo incluido (mismo
// criterio que proveedor_tarifas/mayorista/guia/pasaje_aereo en el backend
// — ver ReservaFacturacionController::construirLineas()). Este helper solo
// desglosa ese número en base+IGV para mostrarlo en vivo, NUNCA cambia el
// precio final que ve el cliente. Misma fórmula que el backend, para que
// el desglose que ve el vendedor coincida con lo que sale en la factura.
export type TipAfeIgv = '10' | '20' | '30';

const PORCENTAJE_IGV_GRAVADO = 18.0;

export const porcentajeIgvDe = (tipAfeIgv: TipAfeIgv): number => (tipAfeIgv === '10' ? PORCENTAJE_IGV_GRAVADO : 0);

export const desglosarPrecioFinal = (precioFinal: number, tipAfeIgv: TipAfeIgv): { base: number; igv: number; porcentaje: number } => {
    const porcentaje = porcentajeIgvDe(tipAfeIgv);
    const base = Math.round((precioFinal / (1 + porcentaje / 100)) * 100) / 100;
    const igv = Math.round((precioFinal - base) * 100) / 100;

    return { base, igv, porcentaje };
};
