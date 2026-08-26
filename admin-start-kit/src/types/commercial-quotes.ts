import type { Client } from "./clients";

export type CommercialQuoteStatus =
    | "borrador"
    | "enviada"
    | "aceptada"
    | "rechazada"
    | "vencida"
    | "anulada";

export type CommercialQuoteItem = {
    id?: number;
    product_id: number | null;
    product?: { id: number; title: string } | null;
    description: string;
    unidad_medida?: string | null;
    quantity: number;
    unit_price: number;
    discount_percent: number;
    subtotal?: number;
};

// Resumen — forma devuelta por GET /commercial-quotes (listado)
export type CommercialQuoteResumen = {
    id: number;
    code: string;
    client: { id: number; full_name: string } | null;
    client_name_free: string | null;
    status: CommercialQuoteStatus;
    total: number;
    currency: string;
    valid_until: string | null;
    converted_sale_id: number | null;
    created_at: string;
};

// Anticipo cobrado para arrancar el trabajo de la cotización, antes de la
// venta final — mismo patrón que AnticipoReserva (Agencia de Viajes).
export type CommercialQuoteAnticipo = {
    id: number;
    advance_id: number;
    monto_asignado: number;
    disponible: number;
    currency: string | null;
    payment_method: string | null;
    fecha_asignacion: string | null;
    sunat_enviado: boolean;
};

// Detalle — forma devuelta por GET /commercial-quotes/{id}
export type CommercialQuoteDetalle = CommercialQuoteResumen & {
    client_phone_free: string | null;
    discount_global: number;
    subtotal: number;
    observacion: string | null;
    converted_at: string | null;
    converted_sale: { id: number; n_operacion: string | null; serie: string; correlativo: number | null } | null;
    registrado_por: string | null;
    items: CommercialQuoteItem[];
    anticipos: CommercialQuoteAnticipo[];
};

export type CommercialQuotesResponse = {
    total: number;
    paginate: number;
    commercial_quotes: CommercialQuoteResumen[];
};

export type CommercialQuoteFormPayload = {
    client_id: number | null;
    client_name_free: string | null;
    client_phone_free: string | null;
    currency: string;
    discount_global: number;
    valid_until: string | null;
    observacion: string | null;
    items: {
        product_id: number | null;
        description?: string | null;
        unidad_medida?: string | null;
        quantity: number;
        unit_price: number;
        discount_percent: number;
    }[];
    status?: CommercialQuoteStatus;
};

// Payload de prellenado — GET /commercial-quotes/{id}/for-sale
export type CommercialQuoteForSale = {
    client: (Pick<Client, "id" | "full_name" | "n_document"> & { type_document?: string }) | null;
    client_name_free: string | null;
    client_phone_free: string | null;
    currency: string;
    observacion: string | null;
    items: {
        product_id: number | null;
        product: { id: number; title: string; stock?: number; price_general?: number } | null;
        description: string;
        quantity: number;
        unit_price: number;
    }[];
};
