import axios from "axios";
import { resolveApiBaseUrl } from "./apiBaseUrl";

function parseJwt(token: string) {
  try {
   // Aquí separamos la segunda parte (el payload) que contiene los datos como la fecha de expiración
    const base64Url = token.split('.')[1]; // Obtenemos el payload que está en formato base64Url

    // El formato base64Url usa '-' y '_' en lugar de '+' y '/' respectivamente
    // Necesitamos reemplazarlos para que sea decodificable en base64
    const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');

    // Decodificamos la cadena base64
    // atob() convierte la cadena base64 a texto legible
    // Luego usamos decodeURIComponent para manejar correctamente los caracteres especiales
    const jsonPayload = decodeURIComponent(atob(base64).split('').map(function(c) {
      return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2); // Convertimos a formato URI
    }).join(''));

    // Finalmente convertimos el payload decodificado a un objeto JSON y lo retornamos
    return JSON.parse(jsonPayload);
  } catch (e) {
    return null;
  }
}

function isTokenExpired(token: string) {
  const decodedToken = parseJwt(token);
  if (!decodedToken || !decodedToken.exp) {
    return true; // Token inválido o sin fecha de expiración
  }
  const currentTime = Math.floor(Date.now() / 1000); // Tiempo actual en segundos
  return decodedToken.exp < currentTime; // Retorna true si el token ha expirado
}

function forceReLogin() {
  localStorage.removeItem("token");
  localStorage.removeItem("user");
  if (window.location.pathname.indexOf("auth/sign-in") === -1) {
    window.location.href = "/auth/sign-in";
  }
}

const instance = axios.create({
  baseURL: resolveApiBaseUrl(),
});

// Lee el token en CADA petición (no una sola vez al cargar el módulo), así
// funciona aunque el login ocurra después de que este archivo se importó, o
// el token se renueve/expire durante la sesión sin recargar la página.
instance.interceptors.request.use((config) => {
  const token = localStorage.getItem("token");
  if (token) {
    if (isTokenExpired(token) && window.location.pathname.indexOf("auth/sign-in") === -1) {
      forceReLogin();
      return Promise.reject(new Error("Token expirado"));
    }
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Un 401 de /auth/login o /auth/register es el resultado NORMAL de credenciales
// inválidas (login.vue ya lo maneja y muestra el mensaje al usuario) — no una sesión
// vencida. Antes esto disparaba forceReLogin() igual, que hace un
// window.location.href duro: la página se recargaba entera ANTES de que el usuario
// llegara a ver el mensaje de error de su propio intento de login fallido, dando la
// falsa impresión de que el sistema "no hacía nada" o "no mostraba ningún error".
const ES_RUTA_DE_LOGIN = /(^|\/)auth\/(login|register)(\?|$)/;

instance.interceptors.response.use(
  (response) => response,
  (error) => {
    const esIntentoDeLogin = ES_RUTA_DE_LOGIN.test(error.config?.url ?? "");
    if (error.response?.status === 401 && !esIntentoDeLogin) {
      forceReLogin();
    }
    return Promise.reject(error);
  }
);

export default instance;
