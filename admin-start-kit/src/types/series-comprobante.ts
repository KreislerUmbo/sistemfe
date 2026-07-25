// Módulo de series de comprobantes.

export type TipoComprobante = {
    codigo: string;
    nombre: string;
    es_documento_sunat: boolean;
    activo_greenter: boolean;
};

export type TiposComprobanteResponse = {
    tipos_comprobante: TipoComprobante[];
};

export type SerieComprobante = {
    id: number;
    branch_id: number;
    branch?: { id: number; name: string };
    tipo_comprobante_codigo: string;
    moneda: 'PEN' | 'USD';
    serie: string;
    correlativo_actual: number;
    correlativo_inicial: number;
    fecha_inicio: string;
    activo: boolean;
    created_at: string;
};

export type SeriesComprobante = {
    total: number;
    paginate: number;
    series_comprobante: SerieComprobante[];
};

export type SerieComprobanteResponse = {
    code: number;
    message: string;
    serie_comprobante?: SerieComprobante;
};
