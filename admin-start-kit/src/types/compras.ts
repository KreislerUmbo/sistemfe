// T-135 — comprobantes de compra.

export interface CompraProveedor {
    id: number;
    razon_social: string;
    nombre_comercial: string | null;
    ciudad: string | null;
    numero_documento: string | null;
}

export interface Compra {
    id: number;
    proveedor_id: number;
    proveedor: CompraProveedor | null;
    proveedor_documento: string | null;
    tipo_comprobante_codigo: string;
    tipo_nombre: string;
    serie: string;
    numero: string;
    fecha_emision: string;
    periodo: string;
    periodo_cerrado: boolean;
    moneda: 'PEN' | 'USD';
    tipo_cambio: string | null;
    base_gravada: string;
    igv: string;
    exonerado: string;
    inafecto: string;
    otros: string;
    total: string;
    condicion: 'contado' | 'credito';
    fecha_vencimiento: string | null;
    compra_referencia_id: number | null;
    referencia: { id: number; tipo_comprobante_codigo: string; serie: string; numero: string } | null;
    tiene_pdf: boolean;
    tiene_xml: boolean;
    estado_sunat: string;
    observaciones: string | null;
    anulada_at: string | null;
    anulada_por: string | null;
    motivo_anulacion: string | null;
    registrada_por: string | null;
    created_at: string;
}

export type MontosCompra = Pick<Compra, 'base_gravada' | 'igv' | 'exonerado' | 'inafecto' | 'otros' | 'total'>;

export interface ComprasListado {
    total: number;
    paginate: number;
    compras: Compra[];
    totales_pen: MontosCompra;
}

export interface TipoComprobanteCompra {
    codigo: string;
    nombre: string;
}

export interface CompraCatalogos {
    tipos: TipoComprobanteCompra[];
    tipo_cambio: { fecha: string; venta: string | number } | null;
}

/** El comprobante que ya estaba registrado (respuesta 409). */
export interface CompraExistente {
    id: number;
    serie: string;
    numero: string;
    periodo: string;
    total: string;
    moneda: string;
    proveedor: string | null;
    ciudad: string | null;
}

export interface CompraPeriodo {
    periodo: string;
    estado: 'abierto' | 'cerrado';
    compras: number;
    cerrado_at: string | null;
    cerrado_por: string | null;
    reabierto_at: string | null;
    reabierto_por: string | null;
    motivo_reapertura: string | null;
    historial: { accion: 'cierre' | 'reapertura'; usuario: string | null; at: string; motivo: string | null }[];
}
