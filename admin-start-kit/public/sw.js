// Service worker del panel (Fase 5 de Créditos, avisos push). Solo recibe y muestra avisos:
// no guarda nada en caché a propósito, así un despliegue nuevo nunca queda escondido detrás de
// archivos viejos. El mensaje llega cifrado desde el servidor como JSON:
// { titulo, cuerpo, url, etiqueta }.

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (evento) => evento.waitUntil(self.clients.claim()))

self.addEventListener('push', (evento) => {
  let datos = {}
  try {
    datos = evento.data ? evento.data.json() : {}
  } catch {
    datos = { cuerpo: evento.data ? evento.data.text() : '' }
  }
  evento.waitUntil(
    self.registration.showNotification(datos.titulo || 'Aviso', {
      body: datos.cuerpo || '',
      icon: '/icons/icon-192.png',
      badge: '/icons/badge-96.png',
      // Misma etiqueta = el aviso nuevo reemplaza al anterior en vez de apilarse.
      tag: datos.etiqueta || 'aviso',
      renotify: true,
      data: { url: datos.url || '/admin' },
    }),
  )
})

self.addEventListener('notificationclick', (evento) => {
  evento.notification.close()
  const destino = new URL(evento.notification.data?.url || '/admin', self.location.origin)
  evento.waitUntil((async () => {
    const ventanas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    // Si el panel ya está abierto en el mismo sitio, se reutiliza esa pestaña.
    const abierta = ventanas.find((v) => new URL(v.url).origin === destino.origin)
    if (abierta) {
      await abierta.focus()
      return abierta.navigate(destino.href)
    }
    return self.clients.openWindow(destino.href)
  })())
})
