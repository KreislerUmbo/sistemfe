// Módulo Caja — Fase 0 (plan-modulo-caja.md §3).

export type PaymentMethod = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    sort_order: number;
    monedas?: string[];
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

// Proveedor del sistema base (proveedores-base). Lo turístico está en types/agencia-viajes.ts.
export type ProveedorBase = {
    id: number;
    razon_social: string;
    nombre_comercial: string | null;
    tipo_documento: string | null;
    numero_documento: string | null;
    direccion: string | null;
    direccion_fiscal: string | null;
    ciudad: string | null;
    es_no_domiciliado: boolean;
    pais: string | null;
    telefono: string | null;
    celular: string | null;
    es_referencial: boolean;
    datos_faltantes: string[];
    estado: boolean;
    created_at: string;
};

// T-134 — lo que devuelve el aviso de documento repetido (409).
export type ProveedorExistente = {
    id: number;
    razon_social: string;
    nombre_comercial: string | null;
    ciudad: string | null;
    direccion: string | null;
    direccion_fiscal: string | null;
    estado: boolean;
};

export type ProveedoresBase = {
    total: number;
    paginate: number;
    proveedores: ProveedorBase[];
};

export type ProveedorBaseResponse = {
    code: number;
    message: string;
    proveedor?: ProveedorBase;
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

// CRUD real agregado 2026-08-17 — antes solo existía index() (Fase 5).
export type Branch = {
    id: number;
    name: string;
    code: string | null;
    address: string | null;
    is_active: boolean;
    created_at: string;
};

export type Branches = {
    total: number;
    paginate: number;
    branches: Branch[];
};

export type BranchResponse = {
    code: number;
    message: string;
    branch?: Branch;
};
