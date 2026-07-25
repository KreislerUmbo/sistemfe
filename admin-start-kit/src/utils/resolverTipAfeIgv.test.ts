import { describe, expect, it } from 'vitest';
import { resolverTipAfeIgv } from './resolverTipAfeIgv';
import type { Product } from '@/types/products';

// Fábrica mínima — solo llena los campos que resolverTipAfeIgv() lee.
const producto = (overrides: Partial<Pick<Product, 'tip_afe_igv_default' | 'is_ivap'>>): Product =>
    ({
        tip_afe_igv_default: '10',
        is_ivap: 1,
        ...overrides,
    }) as Product;

// Bloque A corregido — ver conversación: destino_venta solo tiene 2 valores
// reales en el código ('amazonia' | 'nacional'), 'exterior' no existe como
// valor de destino (se representa con isExportacion aparte). No existe un
// producto "exonerado_amazonia" persistido — la exoneración Amazonía es
// puramente dinámica según destino, aplicada a cualquier producto que no
// esté ya exonerado/inafecto/IVAP a nivel de producto.
describe('resolverTipAfeIgv', () => {
    it.each([
        // [descripción, tip_afe_igv_default, is_ivap, destino, isExportacion, esperado]
        ['A1: gravado + nacional', '10', 1, 'nacional', 0, '10'],
        ['A2 (corregido): gravado + amazonia → SÍ se exonera por destino', '10', 1, 'amazonia', 0, '20'],
        ['A5: exonerado a nivel de producto + nacional', '20', 1, 'nacional', 0, '20'],
        ['A5b: exonerado a nivel de producto + amazonia (destino irrelevante)', '20', 1, 'amazonia', 0, '20'],
        ['A6: inafecto a nivel de producto + nacional', '30', 1, 'nacional', 0, '30'],
        ['A6b: inafecto a nivel de producto + amazonia (destino irrelevante)', '30', 1, 'amazonia', 0, '30'],
        ['A7: gravado + exportación → exportación gana', '10', 1, 'nacional', 1, '40'],
        ['A8 (resuelto): producto exonerado + exportación → exportación gana igual', '20', 1, 'nacional', 1, '40'],
        ['A9: producto inafecto + exportación → exportación gana igual', '30', 1, 'amazonia', 1, '40'],
        ['A10 (resuelto): inafecto + exportación → exportación gana, sin excepción', '30', 1, 'nacional', 1, '40'],
        // IVAP — ausente del catálogo original de la matriz, real en el código
        ['IVAP: arroz pilado + nacional', '10', 2, 'nacional', 0, '17'],
        ['IVAP: arroz pilado + amazonia (destino no lo cambia)', '10', 2, 'amazonia', 0, '17'],
        ['IVAP: is_ivap gana sobre tip_afe_igv_default nulo/gravado', null as unknown as string, 2, 'nacional', 0, '17'],
        ['IVAP + exportación → exportación gana igual', '10', 2, 'nacional', 1, '40'],
        // tip_afe_igv_default nulo (dato real encontrado en umbo, producto id=33)
        // sin is_ivap=2 cae al fallback '10' vía "?? '10'" y de ahí a las reglas normales
        ['tip_afe_igv_default null sin IVAP → fallback gravado', null as unknown as string, 1, 'nacional', 0, '10'],
    ])('%s', (_desc, tip_afe_igv_default, is_ivap, destino, isExportacion, esperado) => {
        const product = producto({ tip_afe_igv_default, is_ivap });
        expect(resolverTipAfeIgv(product, destino, isExportacion)).toBe(esperado);
    });
});
