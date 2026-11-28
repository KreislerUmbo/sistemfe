import { describe, expect, it } from 'vitest';
import {
    datosDeOrigen, enMonedaDeLaCompra, enMonedaDelPago, esSaldoAFavor, faltaEnOrigen, filasAplicadas, origenVacio, textoDiferencia,
} from './pagosProveedor';

describe('pagos a proveedores', () => {
    it('envía solo lo del origen elegido', () => {
        const caja = { ...origenVacio(), payment_method_id: 1, cuenta_bancaria_id: 9, numero_operacion: 'X' };
        expect(datosDeOrigen(caja)).toEqual({ origen: 'caja', payment_method_id: 1 });

        const banco = { ...caja, origen: 'banco' as const, numero_operacion: ' 123 ', fecha: '2026-03-10' };
        expect(datosDeOrigen(banco)).toEqual({ origen: 'banco', cuenta_bancaria_id: 9, numero_operacion: '123', fecha: '2026-03-10' });
    });

    it('dice qué falta para pagar', () => {
        expect(faltaEnOrigen(origenVacio())).toBe('Elige el método de pago.');
        const banco = { ...origenVacio(), origen: 'banco' as const };
        expect(faltaEnOrigen(banco)).toBe('Elige la cuenta bancaria.');
        expect(faltaEnOrigen({ ...banco, cuenta_bancaria_id: 1 })).toBe('Escribe el número de operación.');
        expect(faltaEnOrigen({ ...banco, cuenta_bancaria_id: 1, numero_operacion: '1' })).toBe('');
    });

    it('reparte sin arrastrar decimales', () => {
        const montos = { 3: '0.10', 4: 0.2, 5: '', 6: '0' };
        expect(filasAplicadas(montos)).toEqual([{ compra_id: 3, monto: '0.10' }, { compra_id: 4, monto: '0.20' }]);
        // Solo las compras en otra moneda llevan tipo de cambio.
        expect(filasAplicadas(montos, { 4: '3.75' })[1]).toEqual({ compra_id: 4, monto: '0.20', tipo_cambio: '3.75' });
    });

    it('convierte entre soles y dólares sin pasarse del pago', () => {
        expect(enMonedaDelPago(100000, 'USD', '3.7523')).toBe(375230);
        expect(enMonedaDelPago(10000, 'PEN', '3.3333')).toBe(3000);
        expect(enMonedaDelPago(10000, 'USD', '')).toBe(0);
        expect(enMonedaDeLaCompra(375230, 'USD', '3.7523')).toBe(100000);
        // Lo propuesto, convertido de vuelta, nunca supera lo que le queda al pago.
        const propuesto = enMonedaDeLaCompra(20000, 'USD', '3.7');
        expect(enMonedaDelPago(propuesto, 'USD', '3.7')).toBeLessThanOrEqual(20000);
    });

    it('nombra la diferencia de cambio', () => {
        expect(textoDiferencia('52.30')).toMatch(/^Pérdida .*52\.30$/);
        expect(textoDiferencia('-50.00')).toMatch(/^Ganancia .*50\.00$/);
        expect(textoDiferencia(null)).toBe('sin T.C.');
    });

    it('reconoce el saldo a favor', () => {
        expect(esSaldoAFavor('-200.00')).toBe(true);
        expect(esSaldoAFavor('0.00')).toBe(false);
        expect(esSaldoAFavor(null)).toBe(false);
    });
});
