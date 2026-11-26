// T-134 — reglas de pantalla del proveedor (alta rápida, formularios y buscadores).

export const esRuc = (numero: string | null | undefined) => /^\d{11}$/.test((numero ?? '').trim())

/** «Casa Andina — Tarapoto»: la ciudad distingue dos establecimientos del mismo RUC. */
export const nombreConCiudad = (nombre: string, ciudad: string | null | undefined) =>
  ciudad ? `${nombre} — ${ciudad}` : nombre

export type DatosRuc = {
  razon_social: string
  nombre_comercial: string
  direccion_fiscal: string
  ciudad: string
}

// La consulta devuelve "-" cuando SUNAT no tiene el dato.
const limpio = (valor: unknown) => {
  const texto = typeof valor === 'string' ? valor.trim() : ''
  return texto === '-' ? '' : texto
}

/** Respuesta de `search-document/ruc/{n}` → campos del proveedor; null si no encontró nada. */
export const datosDeConsultaRuc = (data: any): DatosRuc | null => {
  if (!data || data.success === false || !limpio(data.razonSocial)) return null
  return {
    razon_social: limpio(data.razonSocial),
    nombre_comercial: limpio(data.nombreComercial),
    direccion_fiscal: limpio(data.direccion),
    ciudad: limpio(data.provincia) || limpio(data.distrito),
  }
}
