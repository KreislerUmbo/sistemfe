// Cobro al contado al facturar una reserva (T-011). El navegador solo calcula para MOSTRAR; el
// backend (CobroVentaService) decide. Tipo de cambio = soles por dólar, siempre: venta en soles
// pagada en dólares = monto × tc; venta en dólares pagada en soles = monto ÷ tc.
import type { CondicionPago } from '@/helpers/condicionPago';

export type MonedaCobro = 'PEN' | 'USD';

export type FormaPagoCobro = {
  code: string;
  name: string;
  monedas: MonedaCobro[];
  affects_cash_count: boolean;
};

export type FilaCobro = {
  method_payment: string;
  moneda_pago: MonedaCobro;
  monto_pagado: number;
  tipo_cambio: number | null;
  nro_operacion: string;
};

// T-012: cobro de un comprobante anulado con NC que se puede usar en esta factura.
export type TraspasoDisponible = { sale_id: number; comprobante: string; disponible: number };
export type FilaTraspaso = { sale_id: number; monto: number };

const aCentavos = (monto: number) => Math.round((Number(monto) || 0) * 100);

export function totalTraspasado(traspasos: FilaTraspaso[]): number {
  return traspasos.reduce((suma, t) => suma + aCentavos(t.monto), 0) / 100;
}

// Equivalente de la fila en la moneda de la venta.
export function equivalenteFila(fila: FilaCobro, monedaVenta: MonedaCobro): number {
  const monto = Number(fila.monto_pagado) || 0;
  if (fila.moneda_pago === monedaVenta) return Math.round(monto * 100) / 100;
  const tc = Number(fila.tipo_cambio) || 0;
  if (tc <= 0) return 0;
  return Math.round((monedaVenta === 'PEN' ? monto * tc : monto / tc) * 100) / 100;
}

export function totalCobrado(filas: FilaCobro[], monedaVenta: MonedaCobro): number {
  return filas.reduce((suma, f) => suma + aCentavos(equivalenteFila(f, monedaVenta)), 0) / 100;
}

// Positivo = falta; negativo = sobra (posible vuelto).
export function diferencia(filas: FilaCobro[], saldo: number, monedaVenta: MonedaCobro, traspasado = 0): number {
  return (aCentavos(saldo) - aCentavos(totalCobrado(filas, monedaVenta)) - aCentavos(traspasado)) / 100;
}

// Vuelto en la moneda pedida; null si no se puede convertir (no hay efectivo en esa moneda).
export function vueltoEn(
  exceso: number, moneda: MonedaCobro, monedaVenta: MonedaCobro, filas: FilaCobro[],
  formas: FormaPagoCobro[]
): number | null {
  if (exceso <= 0) return 0;
  if (moneda === monedaVenta) return exceso;
  const efectivoEnEsaMoneda = filas.find((f) => f.moneda_pago === moneda && Number(f.tipo_cambio) > 0
    && formas.find((p) => p.code === f.method_payment)?.affects_cash_count);
  if (!efectivoEnEsaMoneda) return null;
  const tc = Number(efectivoEnEsaMoneda.tipo_cambio);
  return Math.round((monedaVenta === 'PEN' ? exceso / tc : exceso * tc) * 100) / 100;
}

// Monedas en que se puede dar el vuelto: la de la venta y las de los pagos en efectivo.
export function monedasDeVuelto(filas: FilaCobro[], monedaVenta: MonedaCobro, formas: FormaPagoCobro[]): MonedaCobro[] {
  const otras = filas
    .filter((f) => formas.find((p) => p.code === f.method_payment)?.affects_cash_count)
    .map((f) => f.moneda_pago);
  return Array.from(new Set<MonedaCobro>([monedaVenta, ...otras]));
}

export function filaNueva(formas: FormaPagoCobro[], monedaVenta: MonedaCobro, monto: number, tipoCambioDia: number | null): FilaCobro {
  const efectivo = formas.find((f) => f.affects_cash_count && f.monedas.includes(monedaVenta)) ?? formas[0];
  return {
    method_payment: efectivo?.code ?? 'EFECTIVO',
    moneda_pago: monedaVenta,
    monto_pagado: monto,
    tipo_cambio: tipoCambioDia,
    nro_operacion: '',
  };
}

// Mensaje de error, o null si el cobro se puede enviar. Solo avisa lo que el usuario puede
// arreglar en la pantalla; el backend vuelve a validar todo.
export function validarCobro(
  filas: FilaCobro[], saldo: number, monedaVenta: MonedaCobro, formas: FormaPagoCobro[], traspasos: FilaTraspaso[] = []
): string | null {
  const traspasado = totalTraspasado(traspasos);
  if (filas.length === 0 && traspasado <= 0) return 'Al contado registra el cobro.';
  if (traspasos.some((t) => !(Number(t.monto) > 0))) return 'Cada traspaso necesita un monto mayor a 0.';
  if (aCentavos(traspasado) > aCentavos(saldo)) return 'Lo traspasado supera el total a cobrar.';
  for (const f of filas) {
    if (!(Number(f.monto_pagado) > 0)) return 'Cada pago necesita un monto mayor a 0.';
    if (f.moneda_pago !== monedaVenta && !(Number(f.tipo_cambio) > 0)) return 'Un pago en otra moneda necesita tipo de cambio.';
    if (f.nro_operacion.length > 50) return 'El n.º de operación no puede pasar de 50 caracteres.';
  }
  const dif = diferencia(filas, saldo, monedaVenta, traspasado);
  if (dif > 0) return `Falta cobrar ${monedaVenta === 'USD' ? 'US$' : 'S/'} ${dif.toFixed(2)}.`;
  if (dif < 0 && !filas.some((f) => formas.find((p) => p.code === f.method_payment)?.affects_cash_count)) {
    return 'Lo cobrado supera el total y no hay efectivo para dar vuelto.';
  }
  return null;
}

// Lo que se manda al emitir. Nada si no corresponde cobrar (crédito, sin saldo o apagado).
export function payloadCobro(
  condicion: CondicionPago, saldo: number, habilitado: boolean, filas: FilaCobro[], vueltoMoneda: MonedaCobro, exceso: number,
  traspasos: FilaTraspaso[] = []
): {
  traspasos?: Array<{ sale_id: number; monto: number }>;
  pagos?: Array<{ method_payment: string; moneda_pago: MonedaCobro; monto_pagado: number; tipo_cambio?: number; nro_operacion?: string }>;
  vuelto_moneda?: MonedaCobro;
} {
  if (!habilitado || condicion !== 'contado' || saldo <= 0) return {};
  return {
    ...(traspasos.length ? { traspasos: traspasos.map((t) => ({ sale_id: t.sale_id, monto: Number(t.monto) })) } : {}),
    pagos: filas.map((f) => ({
      method_payment: f.method_payment,
      moneda_pago: f.moneda_pago,
      monto_pagado: Number(f.monto_pagado),
      tipo_cambio: f.tipo_cambio ? Number(f.tipo_cambio) : undefined,
      nro_operacion: f.nro_operacion.trim() || undefined,
    })),
    ...(exceso > 0 ? { vuelto_moneda: vueltoMoneda } : {}),
  };
}
