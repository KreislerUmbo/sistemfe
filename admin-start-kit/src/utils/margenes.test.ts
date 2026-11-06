import { describe, expect, it } from 'vitest';
import { formatMonto, formatPct, ganancia, margenPct, markupPct } from './margenes';

describe('margenes (T-087)', () => {
    it('costo 100, precio 125 → markup 25, margen 20, ganancia 25', () => {
        expect(markupPct(100, 125)).toBeCloseTo(25);
        expect(margenPct(100, 125)).toBeCloseTo(20);
        expect(ganancia(100, 125)).toBe(25);
    });

    it('costo 0 → markup null, pero margen y ganancia sí existen', () => {
        expect(markupPct(0, 50)).toBeNull();
        expect(margenPct(0, 50)).toBeCloseTo(100);
        expect(ganancia(0, 50)).toBe(50);
    });

    it('precio 0 → margen null, markup −100', () => {
        expect(margenPct(100, 0)).toBeNull();
        expect(markupPct(100, 0)).toBeCloseTo(-100);
    });

    it('falta un dato → null', () => {
        expect(markupPct(null, 100)).toBeNull();
        expect(margenPct(100, undefined)).toBeNull();
        expect(ganancia(null, null)).toBeNull();
    });

    it('acepta números como texto (la API manda decimales como string)', () => {
        expect(markupPct('100.00', '125.00')).toBeCloseTo(25);
    });

    it('pérdida: precio menor al costo da negativos', () => {
        expect(ganancia(100, 80)).toBe(-20);
        expect(markupPct(100, 80)).toBeCloseTo(-20);
        expect(margenPct(100, 80)).toBeCloseTo(-25);
    });

    it('formatea solo al mostrar', () => {
        expect(formatPct(25)).toBe('25.0 %');
        expect(formatPct(null)).toBe('—');
        expect(formatMonto(25)).toBe('25.00');
        expect(formatMonto(null)).toBe('—');
    });
});
