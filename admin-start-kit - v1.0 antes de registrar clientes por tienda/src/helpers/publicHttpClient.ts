import axios from 'axios';

const publicHttpClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL, // ej: http://localhost:8000/api
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

export default publicHttpClient;
//este archivo es para hacer peticiones a la api sin autenticación para el portal del sistema donde estan las categorias y productos