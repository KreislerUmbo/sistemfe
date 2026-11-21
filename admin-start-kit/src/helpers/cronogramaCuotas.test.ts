import { describe, it, expect } from 'vitest';
import { modoInicialCronograma } from './cronogramaCuotas';

describe('modoInicialCronograma (T-118)', () => {
    it('sin cuotas o con una abre en una cuota', () => {
        expect(modoInicialCronograma(0, true)).toBe('una');
        expect(modoInicialCronograma(1, true)).toBe('una');
    });

    it('con varias cuotas guardadas abre en varias', () => {
        expect(modoInicialCronograma(2, true)).toBe('varias');
    });

    it('donde no se permite una cuota, siempre varias', () => {
        expect(modoInicialCronograma(0, false)).toBe('varias');
        expect(modoInicialCronograma(1, false)).toBe('varias');
    });
});
