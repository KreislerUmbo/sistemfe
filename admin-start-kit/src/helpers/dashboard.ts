// Inicio (Dashboard): formato de montos por moneda y textos. Sin aritmética de dinero: los montos
// llegan sumados del backend y aquí solo se presentan.

const formatos = new Map<string, Intl.NumberFormat>()

/** "1234.5", "PEN" → "S/ 1,234.50"; "USD" → "US$ 1,234.50". Espacio normal tras el símbolo. */
export function formatoMoneda(monto: string | number | null | undefined, moneda = 'PEN'): string {
  const codigo = (moneda || 'PEN').toUpperCase()
  if (!formatos.has(codigo)) {
    formatos.set(codigo, new Intl.NumberFormat('es-PE', { style: 'currency', currency: codigo, currencyDisplay: 'symbol' }))
  }
  const numero = typeof monto === 'number' ? monto : Number.parseFloat(monto ?? '')
  const texto = formatos.get(codigo)!.format(Number.isFinite(numero) ? numero : 0).replace(/ /g, ' ')
  // es-PE escribe "USD" o "$" según el motor; en Perú se usa "US$".
  return codigo === 'USD' ? texto.replace(/^(-?)(USD|US\$|\$)\s?/, '$1US$ ') : texto
}

/** "12.5" → { texto: "+12.5 %", clase: "text-success" }; null → sin comparación. */
export function textoVariacion(variacion: string | null): { texto: string; clase: string } | null {
  if (variacion === null) return null
  const valor = Number.parseFloat(variacion)
  if (valor > 0) return { texto: `+${variacion} %`, clase: 'text-success' }
  if (valor < 0) return { texto: `${variacion} %`, clase: 'text-danger' }
  return { texto: '0.0 %', clase: 'text-muted' }
}

/** "2026-10-02" → "jueves 2 de octubre" (sin corrimiento de zona horaria). */
export function fechaTexto(ymd: string): string {
  const [anio, mes, dia] = ymd.split('-').map(Number)
  return new Intl.DateTimeFormat('es-PE', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' })
    .format(new Date(Date.UTC(anio, mes - 1, dia)))
    .replace(',', '')
}
