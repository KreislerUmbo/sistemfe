// T-135 — comprobantes de compra.
import type { AplicacionDeCompra, CuentaParaPagar, EstadoPago, MetodoDeCaja } from '@/types/pagosProveedor';

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
    /** T-136: derivados; null en anuladas y notas de crédito. */
    saldo: string | null;
    estado_pago: EstadoPago | null;
    por_aprobar: string | null;
    /** Solo en el detalle. */
    aplicaciones_pago?: AplicacionDeCompra[];
    compra_referencia_id: number | null;
    con_credito_fiscal: boolean;
    /** Solo en el detalle (`GET compras/{id}`). */
    lineas?: CompraLinea[];
    devolvible?: { product_id: number; maximo: number }[];
    /** T-152: solo en el detalle y solo en Agencia. */
    asignaciones?: CompraAsignacion[];
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

export interface CompraLinea {
    id: number;
    product_id: number | null;
    producto: { id: number; title: string; sku: string | null } | null;
    /** T-162: como viene en el comprobante del proveedor. */
    descripcion: string;
    unidad_medida: string;
    codigo_proveedor: string | null;
    tipo_afectacion_igv: string | null;
    factor: string;
    cantidad: string;
    valor_unitario: string;
    valor_venta: string;
    igv: string;
    total: string;
    /** null en una línea sin producto. */
    costo_unitario_base: string | null;
}

/** Una línea en el formulario. `igv` null = se propone solo. */
export interface LineaCompraForm {
    /** null = línea sin producto (servicio, flete): no mueve stock. */
    product_id: number | null;
    /** Nombre y código del producto del catálogo, si tiene. */
    titulo: string;
    sku: string | null;
    descripcion: string;
    unidad_medida: string;
    codigo_proveedor: string;
    /** Vacío = la de la cabecera. */
    tipo_afectacion_igv: string;
    factor: string;
    cantidad: string | number;
    valor_unitario: string | number;
    igv: string | number | null;
    /** En una nota de crédito: unidades base que todavía se pueden devolver. */
    maximo?: number;
}

export type MontosCompra = Pick<Compra, 'base_gravada' | 'igv' | 'exonerado' | 'inafecto' | 'otros' | 'total'>;

export interface ComprasListado {
    total: number;
    paginate: number;
    compras: Compra[];
    totales_pen: MontosCompra;
    /** T-152: giro Agencia. */
    permite_asignar: boolean;
}

export interface TipoComprobanteCompra {
    codigo: string;
    nombre: string;
}

export interface CompraCatalogos {
    tipos: TipoComprobanteCompra[];
    tipo_cambio: { fecha: string; venta: string | number } | null;
    cuentas_bancarias: CuentaParaPagar[];
    metodos_pago: MetodoDeCaja[];
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

// T-152 — reparto de una compra entre reservas, salidas y «general».
export type DestinoAsignacion = 'reserva' | 'salida' | 'general';

export interface CompraAsignacion {
    destino: DestinoAsignacion;
    reserva_id: number | null;
    salida_operativa_id: number | null;
    monto: string;
    titulo: string;
    usuario: string | null;
}

/** Bloque «Compras» de la ficha de una reserva o salida. */
export interface CompraDeFicha {
    compra_id: number;
    fecha_emision: string;
    tipo_comprobante_codigo: string;
    tipo_nombre: string;
    serie: string;
    numero: string;
    proveedor: { razon_social: string; ciudad: string | null } | null;
    moneda: 'PEN' | 'USD';
    monto: string;
    total: string;
}
