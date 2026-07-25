import type { Product } from '@/types/products';

// ── Resolver tip_afe_igv según las reglas de negocio ─────────────
// Extraído de sale/register.vue y sale/edit.vue (tenían copias idénticas)
// para poder testearlo de forma aislada. Comportamiento sin cambios —
// misma precedencia, mismos valores de retorno.
// producto + destino + exportación → tip_afe_igv correcto
export const resolverTipAfeIgv = (
    product: Product,
    destino: string,
    isExportacion: number,
): string => {
    // Exportación siempre gana sobre cualquier otra regla
    if (isExportacion === 1) return '40';

    // Producto exonerado o inafecto por naturaleza propia
    // (no depende del destino — ej: medicamentos del Apéndice I)
    if (['20', '30'].includes(product.tip_afe_igv_default ?? '10')) {
        return product.tip_afe_igv_default;
    }

    // IVAP (arroz pilado) — siempre aplica independientemente del destino
    if (product.is_ivap === 2) return '17';

    // Ley 27037 Amazonía — bien o servicio entregado en la zona
    // El cliente puede ser de cualquier lugar — lo que importa es el destino
    if (destino === 'amazonia') return '20';

    // Caso por defecto: gravado con IGV normal
    return '10';
};
