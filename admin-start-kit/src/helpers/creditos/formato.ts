// Módulo Créditos — presentación de montos y fechas (00 §4). El backend envía los
// montos como string decimal; aquí solo se formatean para mostrar, nunca se suman ni
// se redondean (el cálculo vive en el motor del backend).

const soles = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

/** "1234.5" → "S/ 1,234.50". Vacío o inválido → "S/ 0.00". */
export function formatoSoles(monto: string | number | null | undefined): string {
  const numero = typeof monto === 'number' ? monto : Number.parseFloat(monto ?? '')

  return soles.format(Number.isFinite(numero) ? numero : 0)
}

/**
 * Centavos enteros (así vienen los detalles de límites del motor) → "S/ 1,234.50". Arma el
 * texto decimal con operaciones enteras exactas, sin dividir en coma flotante.
 */
export function formatoCentavos(centavos: number | null | undefined): string {
  if (centavos === null || centavos === undefined || !Number.isInteger(centavos)) return formatoSoles(0)
  const signo = centavos < 0 ? '-' : ''
  const absoluto = Math.abs(centavos)

  return formatoSoles(`${signo}${Math.trunc(absoluto / 100)}.${String(absoluto % 100).padStart(2, '0')}`)
}

/** "2026-10-01" → "01/10/2026" (sin pasar por Date: evita corrimientos de zona horaria). */
export function formatoFecha(ymd: string | null | undefined): string {
  if (!ymd) return ''
  const [anio, mes, dia] = ymd.slice(0, 10).split('-')

  return dia && mes && anio ? `${dia}/${mes}/${anio}` : ymd
}

/** Fecha de hoy en Lima como "YYYY-MM-DD" (la fecha de desembolso de un crédito nuevo). */
export function hoyEnLima(ahora: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit', day: '2-digit' }).format(ahora)
}
