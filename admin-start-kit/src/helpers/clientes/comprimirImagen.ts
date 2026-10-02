// Fotos del cliente (DNI, foto) tomadas con el celular: 3-5 MB cada una. Se reducen en el
// navegador antes de subirlas (menos datos móviles y subidas más rápidas en la calle); el
// backend las vuelve a reducir igual (ClienteCreditoService, 1600 px / JPEG 80), así que
// esto es solo para la subida.
export const LADO_MAXIMO_PX = 1600
const CALIDAD = 0.8

/** Tamaño final manteniendo la proporción; nunca agranda. */
export function medidasReducidas(ancho: number, alto: number, maximo = LADO_MAXIMO_PX): { ancho: number; alto: number } {
  const mayor = Math.max(ancho, alto)
  if (mayor <= maximo) return { ancho, alto }
  const factor = maximo / mayor
  return { ancho: Math.round(ancho * factor), alto: Math.round(alto * factor) }
}

/**
 * JPEG reducido, o el archivo original si el navegador no puede procesarlo (formato raro,
 * sin canvas): el backend igual lo valida y lo reduce.
 */
export async function comprimirImagen(archivo: File): Promise<File> {
  if (!archivo.type.startsWith('image/') || typeof createImageBitmap !== 'function') return archivo
  try {
    // imageOrientation: la foto del celular respeta la rotación EXIF.
    const imagen = await createImageBitmap(archivo, { imageOrientation: 'from-image' })
    const { ancho, alto } = medidasReducidas(imagen.width, imagen.height)
    const lienzo = document.createElement('canvas')
    lienzo.width = ancho
    lienzo.height = alto
    lienzo.getContext('2d')!.drawImage(imagen, 0, 0, ancho, alto)
    imagen.close()

    const blob = await new Promise<Blob | null>((resolver) => lienzo.toBlob(resolver, 'image/jpeg', CALIDAD))
    if (!blob || blob.size >= archivo.size) return archivo
    return new File([blob], archivo.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' })
  } catch {
    return archivo
  }
}
