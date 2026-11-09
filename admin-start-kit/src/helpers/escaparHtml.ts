// T-092: todo texto escrito por un usuario (nombre de guía, de combo, de
// alternativa, de un archivo) o devuelto por el servidor pasa por aquí antes de
// meterse en el `html:` de un Swal o en cualquier HTML armado a mano. Sin esto,
// un guía llamado `<img src=x onerror=alert(1)>` ejecutaba código en el navegador
// de otro usuario del mismo tenant. Para HTML con formato guardado (Quill) se usa
// `sanearHtml()`; esto es para texto plano: lo muestra literal.
const ENTIDADES: Record<string, string> = {
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#39;',
}

export function escaparHtml(texto: string | number | null | undefined): string {
  if (texto === null || texto === undefined) return ''
  return String(texto).replace(/[&<>"']/g, (c) => ENTIDADES[c])
}
