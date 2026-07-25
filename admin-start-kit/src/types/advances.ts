import type { Client } from "./clients";
import type { Sale } from "./sales";
import type { Note } from "./notes";

export type AdvanceStatus =
    | "pending"
    | "partially_applied"
    | "applied"
    | "partially_refunded"
    | "refunded";

export type AdvanceApplication = {
    id: number;
    advance_id: number;
    sale_id: number;
    amount_applied: number;
    sale?: Sale;
};

export type AdvanceRefundRecord = {
    id: number;
    advance_id: number;
    note_id: number;
    amount_refunded: number;
    reason?: string | null;
    note?: Note;
};

export type Advance = {
    id: number;
    client_id: number;
    sale_id: number;
    amount: number;
    applied_amount: number;
    refunded_amount: number;
    currency: string;
    status: AdvanceStatus;
    payment_method: string;
    notes?: string | null;
    created_at: string;
    client?: Client;
    sale?: Sale;
    applications?: AdvanceApplication[];
    refunds?: AdvanceRefundRecord[];
};

export type Advances = {
    total: number;
    paginate: number;
    advances: Advance[];
};

export type AdvanceResponse = {
    code?: number;
    message: string;
    advance_id?: number;
    sale_id?: number;
};

// Forma compacta devuelta por GET /clients/{id}/advances — usada en el
// checkout de venta (ver register.vue) para el selector de adelantos
// disponibles.
export type ClientAdvance = {
    id: number;
    sale_id: number;
    amount: number;
    applied_amount: number;
    refunded_amount: number;
    available_balance: number;
    currency: string;
    status: AdvanceStatus;
};

export type RefundResponse = {
    code: number;
    message: string;
    note?: Note;
};
