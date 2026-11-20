import { describe, it, expect } from 'vitest';
import { getPrecioBaseSinIgv } from './useProductPricing';
import type { Product } from '@/types/products';

const tasas = { igvGeneral: 18, ivap: 4 };
const producto = (extra: Partial<Product> = {}) => ({ include_igv: 1, tip_afe_igv_default: '10', ...extra }) as Product;

describe('getPrecioBaseSinIgv (T-116)', () => {
    it('no deja errores de coma flotante: 53.10 con IGV da 45 exacto', () => {
        expect(getPrecioBaseSinIgv(53.1, producto(), tasas)).toBe(45);
    });

    it('redondea a 6 decimales un precio que no divide exacto', () => {
        expect(getPrecioBaseSinIgv(10, producto(), tasas)).toBe(8.474576);
    });

    it('sin IGV incluido devuelve el mismo precio', () => {
        expect(getPrecioBaseSinIgv(53.1, producto({ include_igv: 0 }), tasas)).toBe(53.1);
    });

    it('producto exonerado no divide', () => {
        expect(getPrecioBaseSinIgv(59, producto({ tip_afe_igv_default: '20' }), tasas)).toBe(59);
    });
});
