// Módulo Caja — Fase 2 (plan-modulo-caja.md §6, §9).

export type Branch = {
    id: number;
    name: string;
    code: string | null;
    address: string | null;
    is_active: boolean;
};

export type CashRegister = {
    id: number;
    branch_id: number;
    branch?: Branch;
    name: string;
    code: string | null;
    type: 'fixed' | 'mobile';
    is_active: boolean;
    blind_close: boolean | null;
    // Resuelto por el backend: blind_close propio si no es null, si no
    // hereda companies.blind_close_default.
    blind_close_resolved: boolean;
    default_opening_amount: string | number;
};

export type SessionUser = {
    id: number;
    name: string;
    surname?: string | null;
    email?: string;
};

export type CashMovement = {
    id: number;
    cash_session_id: number;
    type: string;
    payment_method_id: number;
    // Relaciones cargadas por el backend (CashSessionController::serializeSession).
    payment_method?: { id: number; code: string; name: string };
    direction: 'in' | 'out';
    amount: string | number;
    reference_type: string | null;
    reference_id: number | null;
    concept_id: number | null;
    concept?: { id: number; name: string; direction: 'in' | 'out' } | null;
    description: string | null;
    counterparty_type: string | null;
    counterparty_id: number | null;
    counterparty_name: string | null;
    counterparty_document: string | null;
    attachment_path: string | null;
    // Módulo Caja — Fase 4: null = movimiento vivo. Si corrected_by no es
    // null, este movimiento ya fue anulado/reemplazado (regla de
    // integridad #1) — su contenido nunca cambia, solo queda anotado.
    corrected_movement_id: number | null;
    corrected_by: number | null;
    corrected_at: string | null;
    // 'confirmed' | 'pending_approval' | 'rejected'
    status: string;
    created_at: string;
};

// Módulo Caja — Fase 4 (plan-modulo-caja.md §6). Payload de POST/PUT
// cash/movements — 'type' solo aplica al crear (update() no permite
// cambiar el tipo de un movimiento manual ya existente).
export type CashMovementPayload = {
    type?: 'manual_income' | 'manual_expense';
    payment_method_id: number;
    amount: number;
    concept_id: number;
    description: string;
    counterparty_type?: 'cliente' | 'proveedor' | 'empleado' | 'socio' | 'otro' | null;
    counterparty_id?: number | null;
    counterparty_name?: string | null;
    counterparty_document?: string | null;
};

export type CashMovementResponse = {
    code: number;
    message: string;
    movement: CashMovement;
};

export type CounterpartySearchResult = {
    id: number;
    name: string;
    document: string | null;
};

export type CashSessionDenomination = {
    id: number;
    denomination: string | number;
    quantity: number;
    subtotal: string | number;
};

export type PaymentMethodTotal = {
    payment_method_id: number;
    payment_method_code: string;
    payment_method_name: string;
    total: number;
};

export type CashSessionDetail = {
    id: number;
    cash_register: CashRegister;
    opened_by_user: SessionUser;
    closed_by_user: SessionUser | null;
    opening_amount: string | number;
    opening_amount_adjusted: boolean;
    opened_at: string;
    closed_at: string | null;
    status: 'open' | 'closed';
    expected_cash: string | number | null;
    // Vista previa en vivo (no persistida) — usar esta para mostrar el
    // "corte X" o el esperado en modo no-ciego antes de confirmar el cierre.
    expected_cash_live: number;
    counted_cash: string | number | null;
    difference: string | number | null;
    difference_reason: string | null;
    closing_notes: string | null;
    movements: CashMovement[];
    denominations: CashSessionDenomination[];
    totals_by_payment_method: PaymentMethodTotal[];
};

export type CashStatusResponse = {
    has_open_session: boolean;
    session: CashSessionDetail | null;
    available_registers?: CashRegister[];
    difference_tolerance: number;
};

export type CashOpenResponse = {
    code: number;
    message: string;
    session: CashSessionDetail;
};

export type CashCloseResponse = {
    code: number;
    message: string;
    session: CashSessionDetail;
};

// Módulo Caja — Fase 5 (plan-modulo-caja.md §9, §11: reportes). Fila
// resumida del historial — CashSessionController::formatearResumenSesion(),
// sin movements/denominations/totals_by_payment_method (eso solo viaja en
// el detalle, CashSessionDetail).
export type CashSessionSummary = {
    id: number;
    cash_register: CashRegister;
    opened_by_user: SessionUser;
    closed_by_user: SessionUser | null;
    opening_amount: string | number;
    opened_at: string;
    closed_at: string | null;
    status: 'open' | 'closed';
    expected_cash: string | number | null;
    counted_cash: string | number | null;
    difference: string | number | null;
};

export type CashSessionsListResponse = {
    total: number;
    paginate: number;
    sessions: CashSessionSummary[];
};

export type CashSessionDetailResponse = {
    session: CashSessionDetail;
};

// Módulo Caja — Fase 5, Paso 2 (dashboard admin, cash.view_all).
export type CashDashboardRegister = {
    cash_register_id: number;
    cash_register_name: string;
    branch: Branch | null;
    has_open_session: boolean;
    session_id: number | null;
    opened_by_user: SessionUser | null;
    opened_at: string | null;
    elapsed_hours: number | null;
    is_stale: boolean;
};

export type CashDashboardResponse = {
    registers: CashDashboardRegister[];
    summary: {
        total_active_registers: number;
        with_open_session: number;
        stale: number;
    };
};
