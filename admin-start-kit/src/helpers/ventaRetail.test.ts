import { describe, expect, it } from 'vitest';
import {
    MAX_DESCRIPCION_LINEA, admiteDecimales, cantidadEnVenta, controlaStock, faltaStockEnEdicion, indiceLineaIntacta, productoSinStock, atajosEfectivo, calcularEfectivo, descripcionAGuardar, motivoBloqueoCobro, normalizarCantidad,
    precioCatalogoPorCliente, type EntradaMotivoCobro,
} from './ventaRetail';

const base: EntradaMotivoCobro = {
    lineas: 1, sinStock: null, hayCliente: true, tipoComprobante: '03',
    clienteTipoDocumento: 'DNI', clienteNDocumento: '45050926', total: 100, moneda: 'PEN',
    esCredito: false, cajaAbierta: true, cobraAhora: true, faltaCobrar: 0, sobraCobrar: 0, efectivoRepetido: false,
    adelantosExceden: false, detraccionSinCodigo: false, creditoSinCronograma: false,
    creditoNoCalza: false, moraSinTasa: false,
};

describe('efectivo y vuelto (CA4)', () => {
    it('vacío cobra exacto', () => {
        expect(calcularEfectivo(151.3, null)).toEqual({ vuelto: 0, falta: 0, pagoQueSeEnvia: 151.3 });
    });
    it('170 sobre 151.30: vuelto 18.70 y se envía 151.30', () => {
        expect(calcularEfectivo(151.3, 170)).toEqual({ vuelto: 18.7, falta: 0, pagoQueSeEnvia: 151.3 });
    });
    it('100 sobre 151.30: falta 51.30', () => {
        const r = calcularEfectivo(151.3, 100);
        expect(r.falta).toBe(51.3);
        expect(r.pagoQueSeEnvia).toBe(100);
    });
    it('atajos: el siguiente billete que cubre, máximo 4', () => {
        expect(atajosEfectivo(151.3)).toEqual([160, 200]);
        expect(atajosEfectivo(8).length).toBeLessThanOrEqual(4);
        expect(atajosEfectivo(8)[0]).toBe(10);
    });
});

describe('cantidades', () => {
    it('solo unidades de peso/volumen aceptan decimales', () => {
        expect(admiteDecimales('KGM')).toBe(true);
        expect(admiteDecimales('NIU')).toBe(false);
        expect(normalizarCantidad('1,250', 'KGM')).toBe(1.25);
        expect(normalizarCantidad('1.7', 'NIU')).toBe(2);
        expect(normalizarCantidad('abc', 'NIU')).toBe(1);
    });
});

describe('precio por cliente (CA3)', () => {
    const p = { price_general: 21.9, price_company: 19.5 };
    it('empresa usa precio empresa; el resto, general', () => {
        expect(precioCatalogoPorCliente(p, 2)).toEqual({ valor: 19.5, nombre: 'Empresa' });
        expect(precioCatalogoPorCliente(p, 1)).toEqual({ valor: 21.9, nombre: 'General' });
        expect(precioCatalogoPorCliente({ price_general: 5, price_company: 0 }, 2).nombre).toBe('General');
    });
});

describe('motivo del botón Cobrar (CA5)', () => {
    it('sin motivo cuando todo está bien', () => expect(motivoBloqueoCobro(base)).toBe(''));
    it('sin productos', () => expect(motivoBloqueoCobro({ ...base, lineas: 0 })).toMatch(/al menos un producto/));
    it('sin stock', () => expect(motivoBloqueoCobro({ ...base, sinStock: 'Aceite' })).toMatch(/stock suficiente de «Aceite»/));
    it('sin cliente', () => expect(motivoBloqueoCobro({ ...base, hayCliente: false })).toMatch(/cliente/));
    it('factura sin RUC', () =>
        expect(motivoBloqueoCobro({ ...base, tipoComprobante: '01' })).toMatch(/RUC/));
    it('boleta > 700 sin DNI', () =>
        expect(motivoBloqueoCobro({ ...base, clienteNDocumento: '00000000', total: 701 })).toMatch(/DNI/));
    it('boleta ≤ 700 sin DNI pasa', () =>
        expect(motivoBloqueoCobro({ ...base, clienteNDocumento: '00000000', total: 700 })).toBe(''));
    it('boleta en USD se convierte con el T.C. (T-109)', () => {
        const usd = { ...base, clienteNDocumento: '00000000', moneda: 'USD', tipoCambioVenta: 3.75 };
        expect(motivoBloqueoCobro({ ...usd, total: 200 })).toMatch(/DNI/); // S/ 750
        expect(motivoBloqueoCobro({ ...usd, total: 186 })).toBe(''); // S/ 697,50
        expect(motivoBloqueoCobro({ ...usd, total: 900, tipoCambioVenta: null })).toBe(''); // sin T.C. no bloquea
    });
    it('crédito sin cliente identificado', () =>
        expect(motivoBloqueoCobro({ ...base, esCredito: true, clienteNDocumento: '' })).toMatch(/identificado/));
    it('caja cerrada solo bloquea si hay que cobrar', () => {
        expect(motivoBloqueoCobro({ ...base, cajaAbierta: false })).toMatch(/caja/);
        expect(motivoBloqueoCobro({ ...base, cajaAbierta: false, cobraAhora: false, esCredito: true, creditoSinCronograma: true })).toMatch(/cronograma/);
        expect(motivoBloqueoCobro({ ...base, cajaAbierta: null })).toBe('');
    });
    it('falta dinero', () =>
        expect(motivoBloqueoCobro({ ...base, faltaCobrar: 51.3 })).toMatch(/Falta S\/ 51.30/));
    it('pagos de más (contado o pago inicial a crédito)', () => {
        expect(motivoBloqueoCobro({ ...base, sobraCobrar: 48.7 })).toMatch(/superan el total en S\/ 48.70/);
        expect(motivoBloqueoCobro({ ...base, esCredito: true, sobraCobrar: 10 })).toMatch(/superan el total/);
        expect(motivoBloqueoCobro({ ...base, sobraCobrar: 0.004 })).toBe('');
    });
});

describe('descripcionAGuardar (T-108)', () => {
    const titulo = 'Antivirus ESET NOD32 1 PC 1 año';

    it('vacío o solo espacios = sin descripción editada', () => {
        expect(descripcionAGuardar('', titulo)).toBeNull();
        expect(descripcionAGuardar('  \n ', titulo)).toBeNull();
    });
    it('igual al nombre del catálogo = sin descripción editada (aunque tenga espacios alrededor)', () => {
        expect(descripcionAGuardar(titulo, titulo)).toBeNull();
        expect(descripcionAGuardar(`  ${titulo}\n`, titulo)).toBeNull();
    });
    it('un texto distinto se guarda limpio y conserva los saltos de línea', () => {
        expect(descripcionAGuardar(`  Equipo SALA-01\r\nVence 18/11/2027 `, titulo)).toBe('Equipo SALA-01\nVence 18/11/2027');
    });
    it('se corta en 500 caracteres', () => {
        expect(descripcionAGuardar('a'.repeat(600), titulo)).toHaveLength(MAX_DESCRIPCION_LINEA);
    });
});

describe('línea intacta y stock por producto (T-112)', () => {
    const p = { id: 1, title: 'Mantenimiento', stock: 5, tipo_bien_servicio: 'BIEN', disponiblidad: 0 };
    const linea = (o: object = {}) => ({ product: p, quantity: 1, price_base: 80, precio_manual: false, descripcion_linea: null, ...o });

    it('CA1: línea sin personalizar → se suma a ella', () => {
        expect(indiceLineaIntacta([linea()], 1, 80)).toBe(0);
    });
    it('CA2: con descripción editada → fila nueva', () => {
        expect(indiceLineaIntacta([linea({ descripcion_linea: 'Otro texto' })], 1, 80)).toBe(-1);
    });
    it('CA3: precio a mano o distinto del sistema → fila nueva', () => {
        expect(indiceLineaIntacta([linea({ precio_manual: true })], 1, 80)).toBe(-1);
        expect(indiceLineaIntacta([linea({ price_base: 60 })], 1, 80)).toBe(-1);
    });
    it('CA4: personalizada + intacta → suma a la intacta', () => {
        expect(indiceLineaIntacta([linea({ descripcion_linea: 'x' }), linea()], 1, 80)).toBe(1);
    });
    it('otro producto o vacío → -1', () => {
        expect(indiceLineaIntacta([], 1, 80)).toBe(-1);
        expect(indiceLineaIntacta([linea()], 2, 80)).toBe(-1);
    });
    it('CA5: el stock se compara con la suma de todas las líneas del producto', () => {
        const ls = [linea({ quantity: 3 }), linea({ quantity: 2, descripcion_linea: 'y' })];
        expect(cantidadEnVenta(ls, 1)).toBe(5);
        expect(productoSinStock(ls)).toBeNull();
        ls[1].quantity = 3;
        expect(productoSinStock(ls)).toBe('Mantenimiento');
    });
    it('un servicio no controla stock', () => {
        const s = { ...p, tipo_bien_servicio: 'SERVICIO' };
        expect(productoSinStock([linea({ product: s, quantity: 99 })])).toBeNull();
    });
});

describe('controlaStock (T-113)', () => {
    it('un bien normal controla stock', () => {
        expect(controlaStock({ tipo_bien_servicio: 'BIEN', disponiblidad: 2, controla_stock: true })).toBe(true);
        expect(controlaStock({})).toBe(true);
    });
    it('servicio, «venta sin stock» o controla_stock=false no controlan', () => {
        expect(controlaStock({ tipo_bien_servicio: 'SERVICIO' })).toBe(false);
        expect(controlaStock({ disponiblidad: 1 })).toBe(false);
        expect(controlaStock({ controla_stock: false })).toBe(false);
    });
});

describe('faltaStockEnEdicion (T-113)', () => {
    it('suma lo que la venta ya tenía descontado', () => {
        // venta con 4, almacén en 0: puede quedarse en 4 o bajar, no subir a 5
        expect(faltaStockEnEdicion({ stock: 0 }, 4, 4)).toBeNull();
        expect(faltaStockEnEdicion({ stock: 0 }, 3, 4)).toBeNull();
        expect(faltaStockEnEdicion({ stock: 0 }, 5, 4)).toBe(4);
        expect(faltaStockEnEdicion({ stock: '1' }, 5, 4)).toBeNull();
    });
    it('producto nuevo en la venta: solo cuenta el almacén', () => {
        expect(faltaStockEnEdicion({ stock: 2 }, 3, 0)).toBe(2);
        expect(faltaStockEnEdicion({ stock: -1 }, 1, 0)).toBe(0);
    });
    it('servicio, «venta sin stock» o controla_stock=false nunca faltan', () => {
        expect(faltaStockEnEdicion({ stock: 0, tipo_bien_servicio: 'SERVICIO' }, 9, 0)).toBeNull();
        expect(faltaStockEnEdicion({ stock: 0, disponiblidad: 1 }, 9, 0)).toBeNull();
        expect(faltaStockEnEdicion({ stock: 0, controla_stock: false }, 9, 0)).toBeNull();
    });
});
