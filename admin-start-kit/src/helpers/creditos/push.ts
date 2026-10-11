// Módulo Créditos (Fase 5, entrega 2) — avisos push en el dispositivo (Web Push). Lo que depende
// del navegador vive aquí; la vista "Mis avisos" solo llama a estas funciones.

export type EstadoPush =
  | 'no-soportado'      // el navegador no tiene push (o la página no es https)
  | 'ios-instalar'      // iPhone/iPad: solo funciona con la app agregada a la pantalla de inicio
  | 'bloqueado'         // el usuario negó el permiso: se reactiva desde la configuración del navegador
  | 'inactivo'          // se puede activar
  | 'activo'            // este dispositivo ya recibe avisos

export const esIOS = (ua: string = navigator.userAgent): boolean =>
  /iPad|iPhone|iPod/.test(ua) || (ua.includes('Macintosh') && typeof document !== 'undefined' && 'ontouchend' in document)

/** ¿Abierta como app instalada (desde la pantalla de inicio)? */
export const enModoApp = (): boolean =>
  window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true

export const soportaPush = (): boolean =>
  window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

/** La clave VAPID del servidor viene en base64url; el navegador la pide en bytes. */
export function claveABytes(base64url: string): Uint8Array {
  const relleno = '='.repeat((4 - (base64url.length % 4)) % 4)
  const base64 = (base64url + relleno).replace(/-/g, '+').replace(/_/g, '/')
  const binario = atob(base64)
  return Uint8Array.from(binario, (c) => c.charCodeAt(0))
}

async function registro(): Promise<ServiceWorkerRegistration> {
  return (await navigator.serviceWorker.getRegistration('/')) ?? navigator.serviceWorker.register('/sw.js')
}

export async function suscripcionActual(): Promise<PushSubscription | null> {
  if (!soportaPush()) return null
  return (await registro()).pushManager.getSubscription()
}

export async function estadoPush(): Promise<EstadoPush> {
  if (esIOS() && !enModoApp()) return 'ios-instalar'
  if (!soportaPush()) return 'no-soportado'
  if (Notification.permission === 'denied') return 'bloqueado'
  return (await suscripcionActual()) ? 'activo' : 'inactivo'
}

/**
 * Pide permiso y suscribe este dispositivo. Devuelve la suscripción lista para mandarla al
 * backend, o el motivo por el que no se pudo.
 */
export async function activarPush(clavePublica: string): Promise<{ ok: true; suscripcion: PushSubscriptionJSON } | { ok: false; estado: EstadoPush }> {
  if (!soportaPush()) return { ok: false, estado: esIOS() ? 'ios-instalar' : 'no-soportado' }
  const permiso = await Notification.requestPermission()
  if (permiso !== 'granted') return { ok: false, estado: permiso === 'denied' ? 'bloqueado' : 'inactivo' }
  const reg = await registro()
  await navigator.serviceWorker.ready
  const existente = await reg.pushManager.getSubscription()
  const suscripcion = existente ?? await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: claveABytes(clavePublica) as BufferSource,
  })
  return { ok: true, suscripcion: suscripcion.toJSON() }
}

/** Quita la suscripción del navegador; devuelve el endpoint para borrarlo también en el backend. */
export async function desactivarPush(): Promise<string | null> {
  const s = await suscripcionActual()
  if (!s) return null
  const endpoint = s.endpoint
  await s.unsubscribe()
  return endpoint
}

/** Nombre corto del dispositivo a partir del User-Agent guardado. */
export function nombreDispositivo(agente: string | null): string {
  if (!agente) return 'Dispositivo'
  const sistema = /Android/i.test(agente) ? 'Android' : /iPhone|iPad|iPod/i.test(agente) ? 'iPhone' : /Windows/i.test(agente) ? 'Windows'
    : /Mac OS/i.test(agente) ? 'Mac' : /Linux/i.test(agente) ? 'Linux' : ''
  const navegador = /Edg\//.test(agente) ? 'Edge' : /SamsungBrowser/.test(agente) ? 'Samsung Internet' : /Firefox\//.test(agente) ? 'Firefox'
    : /Chrome\//.test(agente) ? 'Chrome' : /Safari\//.test(agente) ? 'Safari' : ''
  return [navegador, sistema].filter(Boolean).join(' · ') || 'Dispositivo'
}
