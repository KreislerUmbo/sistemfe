// Condición de pago al facturar una reserva (fila F5, 24-oct-2026). Misma
// regla que el backend (App\Services\CronogramaCreditoValidator), revisada
// antes de emitir para avisar sin ir al servidor.
import type { CuotaCronograma } from '@/components/CronogramaCuotasCampos.vue';

export type CondicionPago = 'contado' | 'credito';

// Mensaje de error, o null si se puede emitir.
export function validarCondicionPago(condicion: CondicionPago, cronograma: CuotaCronograma[], saldo: number): string | null {
  if (condicion !== 'credito') return null;
  if (cronograma.length === 0) return 'A crédito: elige la fecha de la cuota o genera el cronograma de cuotas.';
  if (cronograma.some((c) => !c.fecha_vencimiento || !(Number(c.monto_programado) > 0))) {
    return 'A crédito: cada cuota necesita monto y fecha de vencimiento.';
  }
  const suma = cronograma.reduce((s, c) => s + (Number(c.monto_programado) || 0), 0);
  if (Math.abs(suma - saldo) >= 0.01) {
    return `A crédito: las cuotas suman ${suma.toFixed(2)} y el saldo a cobrar es ${saldo.toFixed(2)}.`;
  }
  return null;
}

// Lo que se manda al emitir: nada si es contado (el backend asume contado).
export function payloadCondicionPago(condicion: CondicionPago, cronograma: CuotaCronograma[]) {
  return condicion === 'credito' ? { condicion_pago: 'credito' as const, cronograma } : {};
}
