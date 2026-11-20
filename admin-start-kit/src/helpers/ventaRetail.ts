// Reglas puras de la pantalla de venta retail (T-106). Sin Vue ni HTTP, para poder probarlas.
// Los cálculos de IGV y totales NO viven acá: siguen en views/sale/register.vue (updateItem/sumDetails).

/** Unidades SUNAT (cat. 03) de peso, volumen o longitud: aceptan cantidad con decimales. */
const UNIDADES_CON_DECIMALES = ['KGM', 'GRM', 'LBR', 'ONZ', 'TNE', 'LTR', 'MLT', 'GLL', 'MTR', 'CMT', 'MTK', 'MTQ'];

export const admiteDecimales = (unidad?: string | null): boolean =>
    UNIDADES_CON_DECIMALES.includes((unidad ?? '').toUpperCase());

/** Cantidad válida para la unidad: decimales (3) solo si la unidad lo permite; mínimo 1 o 0.001. */
export const normalizarCantidad = (valor: unknown, unidad?: string | null): number => {
    const n = parseFloat(String(valor).replace(',', '.'));
    if (admiteDecimales(unidad)) return Math.max(0.001, Number((isFinite(n) && n > 0 ? n : 1).toFixed(3)));
    return Math.max(1, Math.round(isFinite(n) ? n : 1) || 1);
};

export const redondear2 = (n: number): number => Math.round((n + Number.EPSILON) * 100) / 100;

/** Atajos de «¿con cuánto paga?»: el siguiente monto de 10/20/50/100/200 que cubre lo que falta (máx. 4). */
export const atajosEfectivo = (restante: number): number[] => {
    const atajos: number[] = [];
    for (const billete of [10, 20, 50, 100, 200]) {
        const v = Math.ceil(restante / billete) * billete;
        if (v > restante + 0.004 && !atajos.includes(v)) atajos.push(v);
    }
    return atajos.slice(0, 4);
};

/**
 * Efectivo: lo recibido vacío (null) = paga exacto. El vuelto es solo una calculadora:
 * el pago que se envía es `pagoQueSeEnvia` (lo que cubre la venta), nunca lo recibido.
 */
export const calcularEfectivo = (restante: number, recibido: number | null) => {
    const rec = recibido ?? restante;
    const diferencia = redondear2(rec - restante);
    return {
        vuelto: diferencia > 0 ? diferencia : 0,
        falta: diferencia < 0 ? -diferencia : 0,
        pagoQueSeEnvia: redondear2(Math.max(0, Math.min(rec, restante))),
    };
};

/** Precio de catálogo según el cliente: tipo empresa (type_client 2) usa precio empresa si existe. */
export const precioCatalogoPorCliente = (
    product: { price_general?: number; price_company?: number },
    typeClient: unknown,
): { valor: number; nombre: 'General' | 'Empresa' } => {
    if (String(typeClient) === '2' && Number(product.price_company) > 0) {
        return { valor: Number(product.price_company), nombre: 'Empresa' };
    }
    return { valor: Number(product.price_general) || 0, nombre: 'General' };
};

/** Cliente con documento real (no vacío ni 00000000). */
export const tieneDocumentoReal = (nDocument?: string | null): boolean => {
    const d = (nDocument ?? '').trim();
    return d !== '' && !/^0+$/.test(d);
};

export type EntradaMotivoCobro = {
    lineas: number;
    /** Título del primer producto con cantidad mayor al stock (solo bienes que controlan stock). */
    sinStock: string | null;
    hayCliente: boolean;
    tipoComprobante: string;
    clienteTipoDocumento?: string | null;
    clienteNDocumento?: string | null;
    total: number;
    moneda: string;
    /** Último T.C. SUNAT (venta) para convertir un total en USD; null = no hay, no se bloquea. */
    tipoCambioVenta?: number | null;
    esCredito: boolean;
    /** null = no se pudo saber (p. ej. sin permiso de caja): no se bloquea. */
    cajaAbierta: boolean | null;
    /** Hay algo que cobrar ahora (contado, o pago inicial a crédito). */
    cobraAhora: boolean;
    faltaCobrar: number;
    /** Lo que los pagos superan al total a cobrar (revisión T-106: la pantalla anterior lo impedía; el backend da 422). */
    sobraCobrar: number;
    /** Solo un pago en efectivo por venta. */
    efectivoRepetido: boolean;
    adelantosExceden: boolean;
    detraccionSinCodigo: boolean;
    creditoSinCronograma: boolean;
    creditoNoCalza: boolean;
    moraSinTasa: boolean;
};

export const LIMITE_BOLETA_SIN_DNI = 700;

/** Primer motivo por el que NO se puede cobrar, en el orden de la tarea; '' si se puede. */
export const motivoBloqueoCobro = (e: EntradaMotivoCobro): string => {
    if (e.lineas === 0) return 'Agrega al menos un producto.';
    if (e.sinStock) return `No hay stock suficiente de «${e.sinStock}».`;
    if (!e.hayCliente) return 'Elige un cliente.';
    const conDoc = tieneDocumentoReal(e.clienteNDocumento);
    if (e.tipoComprobante === '01' && (e.clienteTipoDocumento !== 'RUC' || !conDoc)) {
        return 'La factura necesita un cliente con RUC.';
    }
    // En dólares se convierte con el último T.C. SUNAT (el backend valida con el mismo valor, T-109).
    const totalEnSoles = e.moneda === 'USD' ? (e.tipoCambioVenta ? e.total * e.tipoCambioVenta : 0) : e.total;
    if (e.tipoComprobante === '03' && !conDoc && totalEnSoles > LIMITE_BOLETA_SIN_DNI) {
        return `SUNAT pide el DNI del cliente en boletas de más de S/ ${LIMITE_BOLETA_SIN_DNI}.`;
    }
    if (e.esCredito && !conDoc) return 'La venta a crédito necesita un cliente identificado.';
    if (e.detraccionSinCodigo) return 'Elige el código de detracción en «Datos de la venta».';
    if (e.adelantosExceden) return 'Los adelantos aplicados superan el total de la venta.';
    if (e.cobraAhora && e.cajaAbierta === false) return 'Abre tu caja para cobrar.';
    if (e.efectivoRepetido) return 'Solo puede haber un pago en efectivo.';
    if (e.sobraCobrar > 0.009) {
        return `Los pagos superan el total en ${e.moneda === 'USD' ? 'US$' : 'S/'} ${e.sobraCobrar.toFixed(2)}.`;
    }
    if (e.esCredito) {
        if (e.creditoSinCronograma) return 'Genera el cronograma de cuotas.';
        if (e.creditoNoCalza) return 'La suma de las cuotas no calza con el monto a financiar.';
        if (e.moraSinTasa) return 'Si aplica mora, la tasa debe ser mayor a 0.';
        return '';
    }
    if (e.faltaCobrar > 0.009) return `Falta ${e.moneda === 'USD' ? 'US$' : 'S/'} ${e.faltaCobrar.toFixed(2)} para completar el pago.`;
    return '';
};

/**
 * Un producto controla stock si es un bien, no está marcado «venta sin stock» (disponiblidad 1) y
 * `controla_stock` no es false. Mismo criterio que `Product::controlaStock()` del backend (T-113).
 */
export const controlaStock = (p: { tipo_bien_servicio?: string; disponiblidad?: number; controla_stock?: boolean }): boolean =>
    p.tipo_bien_servicio !== 'SERVICIO' && p.disponiblidad !== 1 && p.controla_stock !== false;

/** SUNAT acepta hasta 500 caracteres en la descripción de una línea (T-108). */
export const MAX_DESCRIPCION_LINEA = 500;

/**
 * Qué se guarda como descripción editada de la línea (T-108): `null` si el texto queda vacío o igual
 * al nombre del catálogo (la línea sale con el nombre de siempre); si no, el texto sin espacios en los
 * extremos y con tope de 500 caracteres.
 */
export const descripcionAGuardar = (texto: string, tituloCatalogo: string): string | null => {
    const limpio = texto.replace(/\r\n?/g, '\n').trim();
    if (limpio === '' || limpio === tituloCatalogo.trim()) return null;
    return limpio.slice(0, MAX_DESCRIPCION_LINEA);
};

/** Lo mínimo de una línea de venta que mira la regla de «línea intacta» (T-112). */
export type LineaComparable = {
    product: { id: number | string; title?: string };
    quantity: number | string;
    price_base: number | string;
    precio_manual?: boolean;
    descripcion_linea?: string | null;
};

/**
 * Índice de la línea de ese producto a la que se le suma la cantidad al elegirlo de nuevo (T-112), o -1
 * si hay que abrir una fila nueva. Una línea es «intacta» si no tiene descripción editada y lleva el
 * precio que pondría el sistema (no puesto a mano y igual a `precioSistema`). Si hay varias, la primera.
 */
export const indiceLineaIntacta = (
    lineas: LineaComparable[],
    productId: number | string,
    precioSistema: number,
): number =>
    lineas.findIndex(l =>
        l.product.id === productId
        && !l.descripcion_linea?.trim()
        && !l.precio_manual
        && Math.abs(Number(l.price_base) - precioSistema) <= 0.0001);

/** Unidades de un producto en toda la venta, sumando todas sus líneas (para comparar con el stock, T-112). */
export const cantidadEnVenta = (lineas: LineaComparable[], productId: number | string): number =>
    lineas.reduce((s, l) => (l.product.id === productId ? s + (Number(l.quantity) || 0) : s), 0);

/** Título del primer producto que controla stock y cuya suma de líneas supera su stock; null si ninguno (T-112). */
export const productoSinStock = (
    lineas: (LineaComparable & { product: { stock?: number | string; tipo_bien_servicio?: string; disponiblidad?: number } })[],
): string | null => {
    const l = lineas.find(x => controlaStock(x.product) && cantidadEnVenta(lineas, x.product.id) > Number(x.product.stock));
    return l ? (l.product.title ?? '') : null;
};
