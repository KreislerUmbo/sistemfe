import axios from 'axios';
import router from '@/router';
import { useClientAuthStore } from '@/stores/clientAuth';

const publicHttpClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL, // ej: http://localhost:8000/api
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

// Interceptor para añadir token de cliente si existe
publicHttpClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('client_token');
  console.log('Token enviado:', token);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Interceptor para manejar errores de autenticación 
publicHttpClient.interceptors.response.use(
  (response) => { return response; },

  (error) => {
    if (error.response?.status === 401) {
      const clientAuth = useClientAuthStore();
      clientAuth.logout();

      router.push({
        path: '/login',
        query: {
          expired: '1'
        }
      });
    }
    return Promise.reject(error);
  }
);

export default publicHttpClient;
//este archivo es para hacer peticiones a la api sin autenticación para el portal del sistema donde estan las categorias y productos