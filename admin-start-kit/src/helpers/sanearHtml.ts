import DOMPurify from 'dompurify'

// T-068: todo HTML guardado por un usuario (descripciones del editor Quill, del
// portal o de los manuales) pasa por aquí antes de un v-html. Sin esto, un
// <img onerror=...> o un <script> guardado por alguien se ejecutaba en el
// navegador de quien lo abría. Conserva el formato (negrita, listas, enlaces).
export function sanearHtml(html: string | null | undefined): string {
  if (!html) return ''
  return DOMPurify.sanitize(html, { USE_PROFILES: { html: true } })
}
