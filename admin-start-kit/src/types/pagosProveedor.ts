// T-136 — pagos a proveedores.

export type OrigenPago = 'caja' | 'banco';
export type EstadoPago = 'pendiente' | 'parcial' | 'pagado' | 'vencido';
export type EstadoDelPago = 'pendiente_aprobacion' | 'vigente' | 'anulado';

export interface CuentaParaPagar {
    id: number;
    banco: string;
    numero_cuenta: string;
    alias: string | null;
    moneda: 'PEN' | 'USD' | null;
}

export interface MetodoDeCaja {
    id: number;
    name: string;
}

/** De dónde sale el dinero, como se escribe en el formulario. */
export interface OrigenPagoForm {
    origen: OrigenPago;
    payment_method_id: number | null;
    cuenta_bancaria_id: number | null;
    numero_operacion: string;
    fecha: string | null;
}

export interface PagoProveedor {
    id: number;
    proveedor_id: number;
    proveedor: { id: number; razon_social: string; nombre_comercial: string | null; numero_documento: string | null } | null;
    fecha: string;
    moneda: 'PEN' | 'USD';
    monto: string;
    aplicado: string;
    a_cuenta: string;
    origen: OrigenPago;
    estado: EstadoDelPago;
    metodo_pago: MetodoDeCaja | null;
    cuenta_bancaria: { id: number; banco: string; numero_cuenta: string; alias: string | null } | null;
    numero_operacion: string | null;
    observaciones: string | null;
    motivo_anulacion: string | null;
    aplicaciones: {
        id: number;
        compra_id: number;
        compra: { id: number; serie: string; numero: string } | null;
        monto: string;
        desaplicada_at: string | null;
        motivo_desaplicacion: string | null;
    }[];
}

/** Una aplicación vista desde la ficha de la compra. */
export interface AplicacionDeCompra {
    id: number;
    monto: string;
    desaplicada_at: string | null;
    motivo_desaplicacion: string | null;
    pago: {
        id: number;
        fecha: string;
        monto: string;
        origen: OrigenPago;
        estado: EstadoDelPago;
        numero_operacion: string | null;
        metodo_pago: string | null;
        cuenta: string | null;
    };
}

export interface CompraPendiente {
    id: number;
    tipo_comprobante_codigo: string;
    serie: string;
    numero: string;
    fecha_emision: string;
    fecha_vencimiento: string | null;
    moneda: 'PEN' | 'USD';
    total: string;
    saldo: string;
    estado_pago: EstadoPago;
    /** Saldo menos lo que ya espera aprobación. */
    maximo: string;
}

// T-137 — cuentas por pagar. Los montos llegan calculados: la pantalla solo los formatea.

export interface CifrasPorPagar {
    moneda: 'PEN' | 'USD';
    pendiente: string;
    vencido: string;
    por_vencer: string;
    antiguedad: { d1_30: string; d31_60: string; d61_90: string; mas_90: string };
    por_aprobar: string;
    a_cuenta: string;
    a_favor: string;
    neto: string;
}

export interface FichaPorPagar {
    proveedor_id: number;
    razon_social: string;
    nombre_comercial: string | null;
    numero_documento: string | null;
    ciudad: string | null;
    monedas: CifrasPorPagar[];
}

export interface GrupoPorPagar {
    clave: string;
    documento: string | null;
    razon_social: string;
    tiene_vencido: boolean;
    monedas: CifrasPorPagar[];
    fichas: FichaPorPagar[];
}

export interface CompraPorPagar {
    id: number;
    proveedor_id: number;
    tipo_comprobante_codigo: string;
    serie: string;
    numero: string;
    fecha_emision: string;
    vencimiento: string;
    dias_atraso: number;
    moneda: 'PEN' | 'USD';
    total: string;
    notas: string;
    aplicado: string;
    por_aprobar: string;
    saldo: string;
    estado_pago: EstadoPago;
}

/** Un pago del proveedor con algo a cuenta o esperando aprobación. */
export interface PagoAbierto {
    id: number;
    proveedor_id: number;
    fecha: string;
    moneda: 'PEN' | 'USD';
    monto: string;
    a_cuenta: string;
    por_aprobar: string;
    origen: OrigenPago;
    estado: EstadoDelPago;
    metodo_pago: string | null;
    cuenta_bancaria: string | null;
    numero_operacion: string | null;
}

export interface DetallePorPagar extends GrupoPorPagar {
    fichas: (FichaPorPagar & { compras: CompraPorPagar[]; pagos: PagoAbierto[] })[];
}
