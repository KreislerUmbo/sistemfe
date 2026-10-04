// Módulo Créditos — abrir y compartir documentos (Fase 4b). El backend devuelve una URL firmada
// de 10 minutos; abrirla no necesita token. Compartir (decisión 01-oct-2026): en el celular,
// "Compartir" nativo con el PDF adjunto (WhatsApp lo recibe como archivo, sin links públicos);
// donde no se puede adjuntar (PC), se descarga el PDF y se abre WhatsApp con un mensaje.
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { interpretarErrorCredito } from './errorCredito'
import { enlaceWhatsappConTexto } from '@/helpers/creditos/documentos'
import type { FormatoPdf } from '@/types/creditos'

type ObtenerUrl = () => Promise<{ url: string }>

export function useDocumentosCredito() {
  const auth = useAuthStore()
  const toast = useToast()
  const ocupado = ref(false)

  const formatoPorDefecto = (): FormatoPdf => (auth.user?.formato_impresion_default === 'ticket80mm' ? 'ticket80mm' : 'a4')

  /** Abre el PDF en otra pestaña. La pestaña se abre ya (antes del await) para que no la bloqueen. */
  async function abrir(obtenerUrl: ObtenerUrl): Promise<void> {
    const ventana = window.open('', '_blank')
    ocupado.value = true
    try {
      const { url } = await obtenerUrl()
      if (ventana) ventana.location.href = url
      else window.location.assign(url)
    } catch (e) {
      ventana?.close()
      toast.warning(interpretarErrorCredito(e).mensaje)
    } finally {
      ocupado.value = false
    }
  }

  async function compartir(obtenerUrl: ObtenerUrl, nombre: string, mensaje: string, telefono?: string | null): Promise<void> {
    ocupado.value = true
    try {
      const { url } = await obtenerUrl()
      const respuesta = await fetch(url)
      if (!respuesta.ok) throw new Error('No se pudo descargar el documento.')
      const archivo = new File([await respuesta.blob()], nombre, { type: 'application/pdf' })

      if (typeof navigator.canShare === 'function' && navigator.canShare({ files: [archivo] })) {
        try {
          await navigator.share({ files: [archivo], text: mensaje })
        } catch (e) {
          // Cerrar la hoja de compartir sin elegir nada no es un error.
          if ((e as DOMException)?.name !== 'AbortError') throw e
        }
        return
      }

      descargar(archivo)
      window.open(enlaceWhatsappConTexto(telefono, mensaje), '_blank', 'noopener')
      toast.success('PDF descargado: adjúntalo en el chat de WhatsApp que se abrió.')
    } catch (e) {
      toast.warning(e instanceof Error && !('response' in e) ? e.message : interpretarErrorCredito(e).mensaje)
    } finally {
      ocupado.value = false
    }
  }

  function descargar(archivo: File) {
    const enlace = document.createElement('a')
    enlace.href = URL.createObjectURL(archivo)
    enlace.download = archivo.name
    enlace.click()
    setTimeout(() => URL.revokeObjectURL(enlace.href), 10_000)
  }

  return { abrir, compartir, formatoPorDefecto, ocupado }
}
