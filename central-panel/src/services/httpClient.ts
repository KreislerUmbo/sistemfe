import axios from 'axios';
import router from '@/router';
import { useAuthStore } from '@/stores/auth';

// httpClient propio de central-panel — mismo patrón/estilo que
// admin-start-kit/src/helpers/http-client.ts (Axios + interceptor de token +
// redirect a login en 401), pero archivo separado: este proyecto nunca importa
// nada de admin-start-kit ni viceversa.
//
// Apunta siempre a las rutas 'central' (auth:central + central.token) — nunca a
// las rutas de tenant. Esas rutas viven bajo Route::prefix('central') en
// routes/api.php SIN el middleware 'tenant'/'tenant.active' (el panel corre
// fuera de la resolución de tenancy por diseño, ver comentario en ese archivo),
// así que el hostname usado para llegar a ellas es irrelevante para stancl/tenancy
// — ver VITE_API_BASE_URL en .env.example para el detalle de qué host usar.
const httpClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
});

httpClient.interceptors.request.use((config) => {
  const token = useAuthStore().token;
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

httpClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      useAuthStore().logout();
      if (router.currentRoute.value.name !== 'login') {
        router.push({ name: 'login' });
      }
    }
    return Promise.reject(error);
  },
);

export default httpClient;
