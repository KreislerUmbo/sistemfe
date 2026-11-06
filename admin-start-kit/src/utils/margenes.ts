// T-087 — Cálculo único de markup, margen y ganancia (Agencia).
//
// Markup = (precio − costo) ÷ costo   → "sobre el costo".
// Margen = (precio − costo) ÷ precio  → "sobre la venta".
// Con costo 100 y precio 125: markup 25 %, margen 20 %, ganancia 25.
//
// Devuelven null si falta un dato o el divisor es 0. NO redondean: el
// redondeo se hace solo al mostrar (formatPct / formatMonto).
// Todavía no resta el IGV (queda para cuando responda el contador).

type Num = number | string | null | undefined;

const aNumero = (v: Num): number | null => {
    if (v === null || v === undefined || v === '') return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
};

export const ganancia = (costo: Num, precio: Num): number | null => {
    const c = aNumero(costo);
    const p = aNumero(precio);
    return c === null || p === null ? null : p - c;
};

export const markupPct = (costo: Num, precio: Num): number | null => {
    const c = aNumero(costo);
    const g = ganancia(costo, precio);
    return g === null || c === null || c === 0 ? null : (g / c) * 100;
};

export const margenPct = (costo: Num, precio: Num): number | null => {
    const p = aNumero(precio);
    const g = ganancia(costo, precio);
    return g === null || p === null || p === 0 ? null : (g / p) * 100;
};

/** Formatea un porcentaje con 1 decimal ("25.0 %"); "—" si es null. */
export const formatPct = (pct: number | null): string => (pct === null ? '—' : `${pct.toFixed(1)} %`);

/** Formatea un monto con 2 decimales ("25.00"); "—" si es null. */
export const formatMonto = (monto: number | null): string => (monto === null ? '—' : monto.toFixed(2));
