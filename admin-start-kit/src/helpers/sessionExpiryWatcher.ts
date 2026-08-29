// src/helpers/sessionExpiryWatcher.ts
// Aviso "tu sesión está por expirar" con opción de renovar en silencio —
// antes de esto, el JWT vencía y http-client.ts hacía un
// window.location.href duro al login sin ningún aviso, perdiendo lo que el
// usuario tuviera sin guardar. AuthController::refresh() ya existía en el
// backend pero nunca había quedado enrutado (rutas/api.php ahora sí lo
// expone en auth/refresh).
import httpClient, { getTokenExpMs } from './http-client';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useAuthStore } from '@/stores/auth';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const AVISO_MS_ANTES_DE_EXPIRAR = 2 * 60 * 1000;

let timeoutAviso: ReturnType<typeof setTimeout> | null = null;
let avisoAbierto = false;

// Llamar después de login y después de cada renovación exitosa (re-arma
// con el nuevo vencimiento) — y una vez al montar la app (App.vue) para
// cubrir el caso de sesión ya abierta desde antes (F5).
export function iniciarVigilanciaSesion() {
  if (timeoutAviso) {
    clearTimeout(timeoutAviso);
    timeoutAviso = null;
  }

  const token = localStorage.getItem('token');
  if (!token) return;

  const expMs = getTokenExpMs(token);
  if (expMs === null) return;

  const msHastaAviso = expMs - Date.now() - AVISO_MS_ANTES_DE_EXPIRAR;
  // Si ya no alcanza a avisar 2 minutos antes (token por vencer o ya
  // vencido), no se programa nada — el interceptor de http-client.ts se
  // encarga de sacar al usuario apenas haga la próxima petición.
  if (msHastaAviso <= 0) return;

  timeoutAviso = setTimeout(mostrarAvisoExpiracion, msHastaAviso);
}

export function detenerVigilanciaSesion() {
  if (timeoutAviso) {
    clearTimeout(timeoutAviso);
    timeoutAviso = null;
  }
}

async function mostrarAvisoExpiracion() {
  if (avisoAbierto) return;
  avisoAbierto = true;

  const resultado = await (Swal as TVueSwalInstance).fire({
    title: 'Tu sesión está por expirar',
    text: 'Por seguridad, se va a cerrar en 2 minutos si no continuás.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Continuar trabajando',
    cancelButtonText: 'Cerrar sesión',
    allowOutsideClick: false,
    allowEscapeKey: false,
    timer: AVISO_MS_ANTES_DE_EXPIRAR,
    timerProgressBar: true,
  });

  avisoAbierto = false;

  if (resultado.isConfirmed) {
    await renovarSesion();
  } else {
    useAuthStore().removeSession();
  }
}

async function renovarSesion() {
  try {
    const res = await httpClient.post('auth/refresh');
    useAuthStore().saveSession({ ...res.data.user, token: res.data.access_token });
    iniciarVigilanciaSesion();
  } catch (error) {
    useAuthStore().removeSession();
  }
}
