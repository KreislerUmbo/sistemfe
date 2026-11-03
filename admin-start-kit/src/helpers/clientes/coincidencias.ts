// T-059 (CRM C4) — cuándo «Nueva consulta» busca clientes que podrían ser la misma persona. Mismo
// umbral que ConsultaController::coincidencias(): el teléfono por sus dígitos (los últimos 9, desde
// 6) y el nombre desde 3 letras. Sin nada que buscar devuelve null y no se llama a la API.

export const MIN_DIGITOS_TELEFONO = 6
export const MIN_LETRAS_NOMBRE = 3

export function parametrosCoincidencias(telefono: string, nombre: string): { telefono?: string; nombre?: string } | null {
  const digitos = telefono.replace(/\D/g, '').slice(-9)
  const limpio = nombre.trim().replace(/\s+/g, ' ')
  const params: { telefono?: string; nombre?: string } = {}
  if (digitos.length >= MIN_DIGITOS_TELEFONO) params.telefono = digitos
  if (limpio.length >= MIN_LETRAS_NOMBRE) params.nombre = limpio
  return Object.keys(params).length ? params : null
}
