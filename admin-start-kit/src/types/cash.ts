// Módulo Caja — Fase 0 (plan-modulo-caja.md §3).

export type PaymentMethod = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    sort_order: number;
    created_at: string;
};

export type PaymentMethods = {
    total: number;
    paginate: number;
    payment_methods: PaymentMethod[];
};

export type PaymentMethodResponse = {
    code: number;
    message: string;
    payment_method?: PaymentMethod;
};

export type Supplier = {
    id: number;
    name: string;
    document: string | null;
    phone: string | null;
    is_active: boolean;
    created_at: string;
};

export type Suppliers = {
    total: number;
    paginate: number;
    suppliers: Supplier[];
};

export type SupplierResponse = {
    code: number;
    message: string;
    supplier?: Supplier;
};

export type CashConcept = {
    id: number;
    name: string;
    direction: 'in' | 'out';
    is_active: boolean;
    created_at: string;
};

export type CashConcepts = {
    total: number;
    paginate: number;
    cash_concepts: CashConcept[];
};

export type CashConceptResponse = {
    code: number;
    message: string;
    cash_concept?: CashConcept;
};
