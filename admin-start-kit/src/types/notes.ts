import type { Product } from "./products";

// Versión compacta de una nota, tal como viene embebida en Sale.notes
// (ver SaleResource::toArray()) — sin note_details.
export type NoteSummary = {
    id: number;
    tipo_doc: "07" | "08";
    serie: string;
    correlativo?: number | null;
    n_operacion?: string | null;
    cod_motivo: string;
    des_motivo: string;
    tipo_afectacion: "total" | "parcial";
    status: "pendiente" | "enviando" | "aceptado" | "rechazado";
    mto_imp_venta: number;
};

export type NoteMotivo = {
    id: number;
    catalogo: "09" | "10";
    codigo: string;
    label: string;
    permite_total: boolean;
    permite_parcial: boolean;
    solo_factura: boolean;
};

export type NoteConfig = {
    motivos: NoteMotivo[];
    product_notes: { data: Product[] };
};

export type NoteDetail = {
    id?: number;
    sale_detail_id?: number | null;
    product_id: number;
    product?: Product;
    tip_afe_igv: string;
    quantity: number;
    price_base: number;
    price_final: number;
    subtotal: number;
    igv: number;
    icbper: number;
    isc: number;
    total_impuestos: number;
    unidad_medida?: string;
    description?: string;
};

export type Note = {
    id: number;
    sale_id: number;
    tipo_doc: "07" | "08";
    tipo_doc_afectado: string;
    serie_afectada: string;
    correlativo_afectado: number;
    serie: string;
    correlativo?: number | null;
    n_operacion?: string | null;
    cod_motivo: string;
    des_motivo: string;
    tipo_afectacion: "total" | "parcial";
    currency: string;
    mto_oper_gravadas: number;
    mto_oper_exoneradas: number;
    mto_oper_inafectas: number;
    mto_oper_exportacion: number;
    mto_igv: number;
    icbper_total: number;
    isc_total: number;
    valor_venta: number;
    mto_imp_venta: number;
    redondeo: number;
    reponer_stock: boolean;
    status: "pendiente" | "enviando" | "aceptado" | "rechazado";
    sunat_error_code?: string | null;
    sunat_error_message?: string | null;
    xml?: string | null;
    cdr?: string | null;
    hash_cpe?: string | null;
    note_details: NoteDetail[];
};

// Ítem enviado en el request de store()/preview() — ver contrato documentado
// en NotaElectronicaController.php
export type NotaItemRequest = {
    sale_detail_id?: number | null;
    product_id: number;
    quantity: number;
    subtotal?: number; // requerido solo si sale_detail_id se omite (concepto libre ND)
};

export type NotaPreviewResponse = {
    totales: Record<string, number>;
    lineas: any[];
};

export type NotaResponse = {
    note?: Note;
    response?: {
        error?: { code?: string | number; message?: string };
        cdrResponse?: { code?: string; message?: string; notes?: string[] };
    };
};
