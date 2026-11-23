// T-118: con qué modo abre el cronograma de una venta a crédito. Lo común es una sola cuota
// («pago a N días»); un cronograma guardado con varias cuotas abre en «varias» para no perderlas.
export type ModoCronograma = 'una' | 'varias';

export const modoInicialCronograma = (cuotasGuardadas: number, permitirUnaCuota: boolean): ModoCronograma =>
    permitirUnaCuota && cuotasGuardadas <= 1 ? 'una' : 'varias';
