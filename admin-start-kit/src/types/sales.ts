import type { Client } from "./clients"
import type { Product } from "./products"
import type { NoteSummary } from "./notes"

export type SaleConfig = {
    today: string,
    n_transaction: string,
    correlativo: string,
    clients: {
        data: Client[],
    }
    products: {
        data: Product[]
    }
    product_notes?: {
        data: Product[]
    }
}

export type SaleDetail = {
    id?: string,
    product_id?: string,
    product: Product,
    product_categorie_id?: string,
    product_categorie?: {
        title: string,
    },
    unidad_medida: string,
    quantity: number,
    discount_percent?: number;
    price_base: number,
    price_final: number,
    discount: number,
    subtotal: number,
    igv: number,
    description?: string,

    mto_valor_venta: number,
    mto_base_igv: number,
    porcentaje_igv: number,
    total_impuestos: number,
    tipo_isc: string,
    monto_isc_fijo: number,
    contenido_neto_litros?: number,

    // opcionales mientras se construye
    tip_afe_igv: string
    icbper: number
    percentage_isc?: number
    per_icbper?: number
    isc: number
}

export type SaleDetailResponse = {
    sale_detail: SaleDetail,
    discount: number,
    igv: number,
    subtotal: number,
    total: number,
    debt: number,
    message?: string,
    code?: number,
}

export type SalePayment = {
    id?: string,
    method_payment: string,
    amount: number,
    date_payment: string,
}
export type Sale = {
    id: string,
    date: string,
    destino: string,
    n_transaction: string,
    user_id: string,
    user: {
        full_name: string,
    },
    client_id: string,
    client: {
        id: string,
        full_name: string,
        type_client?: number,
        n_document: string,
        address?: string,
        phone?: string
    },
    n_document: string,
    correlativo: string,
    n_operacion: string,
    type_client: number,
    subtotal: number,
    discount: number,
    total: number,
    // 
    igv_general: number,
    total_general: number,
    // 
    igv: number,
    state_sale: number,
    state_payment: number,
    state_entrega: number,
    type_payment: number,
    debt: number,
    paid_out: number,
    // Módulo Amortizaciones — ver SaleResource.php
    condicion_pago?: string,
    credit_type?: string | null,
    saldo_pendiente?: number,
    aplica_mora?: boolean,
    tasa_mora?: number,
    tipo_mora?: string | null,
    tiene_cobros_formales?: boolean,
    description: string,
    created_at: string,
    created_at_format: string,
    sale_details: SaleDetail[],
    sale_payments: SalePayment[],
    // 
    serie: string,
    tipo_comprobante_codigo?: string | null,
    retencion_igv: number,
    discount_global: number,
    n_comprobante_anticipo: string,
    amount_anticipo: number,
    cdr: string,
    xml: string,
    sunat_error_message?: string | null,
    sunat_sent_at?: string | null,
    igv_discount_general: number,
    is_exportacion: number,
    currency: string,
    ce_anticipo: any,
    // Notas de Crédito/Débito ya emitidas sobre esta venta (campos
    // compactos — ver SaleResource::toArray()). Requiere que el backend
    // haya hecho with('notes'); si no, viene como [].
    notes: NoteSummary[],
    monto_retencion?: number,
    monto_detraccion?: number,
    monto_percepcion?: number,
    mto_oper_exoneradas?: number,
    codigo_detraccion: string
    porcentaje_detraccion: number,
    is_locked?: boolean;
    type?:string,
    mto_oper_gravadas?: number,
}

export type Sales = {
    total: number,
    paginate: number,
    sales: {
        data: Sale[],
    },
}

export type SaleResponse = {
    message: string | number,
    code?: number,
    sale?: Sale,
    sale_id?: string | number,
    error?: any,

}