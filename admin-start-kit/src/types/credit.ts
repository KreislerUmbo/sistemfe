// Módulo Amortizaciones — Fase 9. Tipos tomados directo de las respuestas de
// CreditPaymentController / CreditReceivablesController (backend, Fases 4/7) —
// no hay recálculo en el frontend, estos son solo shapes de lectura.

export type EstadoCuentaCredito = "al_dia" | "por_vencer" | "vencida";

// Fila de venta abierta — misma forma en credit-summary (ventas[]) y
// credit-sales (sales[], con client_nombre/client_n_document agregados).
export type CreditSaleRow = {
    sale_id: number;
    client_id: number;
    n_operacion: string | null;
    date: string | null;
    condicion_pago: string;
    credit_type: string | null;
    saldo_pendiente: number;
    mora_acumulada: number;
    cuotas_vencidas: number;
    proxima_cuota_vencimiento: string | null;
    estado: EstadoCuentaCredito;
    client_nombre?: string;
    client_n_document?: string;
};

// GET /credit-sales — vista B
export type CreditSalesResponse = {
    total: number;
    paginate: number;
    sales: CreditSaleRow[];
};

// GET /clients/credit-summary-list — vista A
export type ClientCreditSummaryRow = {
    client_id: number;
    client_nombre: string;
    n_document: string | null;
    deuda_total: number;
    cantidad_ventas_abiertas: number;
    cantidad_cuotas_vencidas: number;
    saldo_a_favor: number;
};

export type CreditSummaryListResponse = {
    total: number;
    paginate: number;
    clients: ClientCreditSummaryRow[];
};

// GET /clients/{client}/credit-summary
export type ClientCreditSummary = {
    client_id: number;
    client_nombre: string;
    saldo_a_favor: number;
    deuda_total: number;
    mora_acumulada_total: number;
    cantidad_ventas_abiertas: number;
    cantidad_cuotas_vencidas_total: number;
    ventas: CreditSaleRow[];
};

// Fila de aplicación devuelta por el preview del FIFO (CreditPaymentAllocator) —
// mismo shape que espera store() en 'aplicaciones', para poder mandarla de
// vuelta ya editada sin renombrar campos.
export type PaymentApplicationPreviewRow = {
    sale_id: number;
    installment_id: number | null;
    numero_cuota: number | null;
    saldo_antes: number;
    monto_aplicado: number;
    saldo_despues: number;
    mora_antes: number;
    monto_mora_aplicado: number;
};

export type ResumenPorVentaRow = {
    sale_id: number;
    saldo_antes: number;
    monto_aplicado: number;
    saldo_despues: number;
    mora_aplicada: number;
};

// POST /clients/{client}/payments/preview
export type PaymentPreviewResponse = {
    client_id: number;
    monto_total: number;
    total_aplicado: number;
    total_mora_aplicada: number;
    monto_no_aplicado: number;
    deuda_restante_cliente: number;
    aplicaciones: PaymentApplicationPreviewRow[];
    resumen_por_venta: ResumenPorVentaRow[];
};

// Fila de aplicación de un pago — PaymentReceiptController::index /
// CreditPaymentController::store.
export type PaymentApplicationRow = {
    id: number;
    payment_receipt_id: number;
    sale_id: number;
    installment_id: number | null;
    monto_aplicado: number;
    monto_mora_cobrado: number;
    orden_aplicacion: number;
    estado: "activo" | "anulada" | "trasladada";
    refund_id: number | null;
    origen_application_id: number | null;
    sale?: {
        id: number;
        n_operacion: string | null;
    };
    installment?: {
        id: number;
        numero_cuota: number;
    } | null;
};

// POST /clients/{client}/payments
export type PaymentStoreResponse = {
    code: number;
    message: string;
    payment_receipt_id: number;
    numero_recibo: string;
    monto_total: number;
    monto_no_aplicado: number;
    total_mora_cobrada: number;
    applications: PaymentApplicationRow[];
};

// Fila de recibo — GET /clients/{client}/payment-receipts (PaymentReceiptController::index)
export type PaymentReceiptRow = {
    // 'recibo': cobro real hecho desde Cuentas por Cobrar (tiene número de
    // recibo, PDF, se puede anular). 'pago_venta': pago inicial registrado
    // directo en la venta (SaleController::store()/SalePaymentController) —
    // no genera recibo formal, solo aparece acá para que el historial no
    // se vea vacío. Ver PaymentReceiptController::index().
    origen: "recibo" | "pago_venta";
    id: number;
    numero_recibo: string | null;
    client_id: number | null;
    fecha_pago: string;
    medio_pago: string;
    nro_operacion: string | null;
    monto_total: number;
    monto_no_aplicado: number;
    estado: "activo" | "anulado";
    motivo_anulacion: string | null;
    anulado_en: string | null;
    created_at: string;
    sale_id: number | null;
    sale_n_operacion: string | null;
    applications: PaymentApplicationRow[];
};

export type PaymentReceiptsResponse = {
    client_id: number;
    total: number;
    paginate: number;
    payment_receipts: PaymentReceiptRow[];
};
