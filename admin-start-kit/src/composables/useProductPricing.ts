import type { Product } from '@/types/products';

export type TasasIgv = {
    igvGeneral: number;
    ivap: number;
};

// Tasa que corresponde al producto según su propia naturaleza tributaria
// (is_ivap / tip_afe_igv_default) — la MISMA tasa que se usó para calcular
// "Precio Base (sin IGV)" al registrar el producto. No confundir con el
// tip_afe_igv resuelto para la línea de venta (que puede variar por destino
// Amazonía o exportación): aquí necesitamos la tasa que en efecto quedó
// incrustada en price_general/price_company, no la que aplica a esta venta.
export const getTasaProductoBase = (product: Product, tasas: TasasIgv): number => {
    if (product.is_ivap === 2) return tasas.ivap;
    if (['20', '30'].includes(product.tip_afe_igv_default ?? '10')) return 0;
    return tasas.igvGeneral;
};

// Invierte el cálculo de "Precio Base (sin IGV)" del registro de producto
// (product/register.vue → getPriceBaseCF/getPriceBaseCE) para obtener el
// valor neto a partir del precio crudo (price_general o price_company).
export const getPrecioBaseSinIgv = (rawPrice: number, product: Product, tasas: TasasIgv): number => {
    const precio = Number(rawPrice) || 0;
    if (!product.include_igv) return precio;
    const tasa = getTasaProductoBase(product, tasas);
    const divisor = 1 + (tasa / 100);
    return precio / divisor;
};
