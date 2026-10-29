import { describe, expect, it } from 'vitest';
import { diferencia, filaNueva, payloadCobro, totalTraspasado, validarCobro, type FormaPagoCobro } from './cobroFactura';

const formas: FormaPagoCobro[] = [{ code: 'EFECTIVO', name: 'Efectivo', monedas: ['PEN', 'USD'], affects_cash_count: true }];

describe('cobro con traspaso (T-012)', () => {
  it('suma lo traspasado en centavos', () => {
    expect(totalTraspasado([{ sale_id: 1, monto: 0.1 }, { sale_id: 2, monto: 0.2 }])).toBe(0.3);
  });

  it('el traspaso resta de lo que falta cobrar', () => {
    expect(diferencia([], 500, 'PEN', 300)).toBe(200);
    const pago = filaNueva(formas, 'PEN', 200, null);
    expect(diferencia([pago], 500, 'PEN', 300)).toBe(0);
  });

  it('un traspaso que cubre todo es válido sin pagos nuevos', () => {
    expect(validarCobro([], 500, 'PEN', formas, [{ sale_id: 1, monto: 500 }])).toBeNull();
    expect(validarCobro([], 500, 'PEN', formas, [])).toBe('Al contado registra el cobro.');
  });

  it('avisa si lo traspasado supera el total o no alcanza', () => {
    expect(validarCobro([], 500, 'PEN', formas, [{ sale_id: 1, monto: 600 }])).toBe('Lo traspasado supera el total a cobrar.');
    expect(validarCobro([], 500, 'PEN', formas, [{ sale_id: 1, monto: 200 }])).toBe('Falta cobrar S/ 300.00.');
  });

  it('manda los traspasos junto con los pagos', () => {
    const pago = filaNueva(formas, 'PEN', 200, null);
    const payload = payloadCobro('contado', 500, true, [pago], 'PEN', 0, [{ sale_id: 7, monto: 300 }]);
    expect(payload.traspasos).toEqual([{ sale_id: 7, monto: 300 }]);
    expect(payload.pagos).toHaveLength(1);
  });
});
