// T-140 — aparte de pagosProveedor.ts: aquel es puro (se prueba sin navegador).
import httpClient from '@/helpers/http-client';
import type { CompraCatalogos } from '@/types/compras';

/** La venta SUNAT de una fecha, como la usa la compra; null si no hay o no se pudo consultar. */
export const ventaSunat = async (fecha: string): Promise<string | null> => {
    try {
        const { data } = await httpClient.get<CompraCatalogos>('compras/catalogos', { params: { fecha } });
        return data.tipo_cambio ? String(data.tipo_cambio.venta) : null;
    } catch {
        return null;
    }
};
