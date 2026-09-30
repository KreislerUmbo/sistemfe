// Etiqueta legible del tipo de un movimiento de caja (cash_movements.type). Compartida por
// el turno activo y el historial; un tipo desconocido se muestra tal cual.
const ETIQUETAS: Record<string, string> = {
  opening_fund: 'Fondo de apertura',
  sale_payment: 'Pago de venta',
  installment_payment: 'Cobro de cuota (venta a crédito)',
  advance_received: 'Adelanto recibido',
  manual_income: 'Ingreso manual',
  manual_expense: 'Egreso manual',
  correction: 'Corrección',
  // Módulo Créditos (CajaCredito)
  credito_desembolso: 'Desembolso de crédito',
  credito_pago: 'Cobro de crédito',
  credito_devolucion_excedente: 'Vuelto de cobro de crédito',
}

export function etiquetaTipoMovimiento(tipo: string): string {
  return ETIQUETAS[tipo] ?? tipo
}
