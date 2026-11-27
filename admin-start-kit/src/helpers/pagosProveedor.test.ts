import { describe, expect, it } from 'vitest';
import { datosDeOrigen, esSaldoAFavor, faltaEnOrigen, filasAplicadas, origenVacio, quedaACuenta } from './pagosProveedor';

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
        expect(quedaACuenta('1.00', montos)).toBe('0.70');
        expect(quedaACuenta('0.20', montos)).toBe('-0.10');
    });

    it('reconoce el saldo a favor', () => {
        expect(esSaldoAFavor('-200.00')).toBe(true);
        expect(esSaldoAFavor('0.00')).toBe(false);
        expect(esSaldoAFavor(null)).toBe(false);
    });
});
