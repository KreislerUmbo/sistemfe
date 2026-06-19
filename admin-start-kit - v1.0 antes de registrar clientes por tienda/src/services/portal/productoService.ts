import publicHttpClient from '@/helpers/publicHttpClient';

export interface ProductoPortal {
  id: number;
  nombre: string;
  slug: string;
  precio: number;
  precioOriginal?: number;
  descuento?: number;
  categoria: string;
  categoria_id: number;
  marca?: string;
  stock: number;
  imagen: string;
  rating: number;
  ratingCount: number;
  descripcion: string;
  include_igv: string;
  especificaciones: { key: string; val: string }[];
}

export interface CategoriaPortal {
  id: number;
  title: string;
  imagen?: string;
}

export const productoService = {//esto es para hacer peticiones a la api
  async listar(params: any = {}) { 
    const response = await publicHttpClient.get('/portal/products', { params });
    // Adapta según la respuesta de tu backend
    // Si tu backend devuelve { data: [], current_page, last_page, total, per_page }
    return {
      products: {
        data: response.data.data,
        current_page: response.data.current_page,
        last_page: response.data.last_page,
        total: response.data.total,
        per_page: response.data.per_page,
      }
    };
  },

  async obtenerPorId(id: number): Promise<ProductoPortal> {
    const response = await publicHttpClient.get(`/portal/products/${id}`);
    return response.data;
  },

  async obtenerCategorias(): Promise<CategoriaPortal[]> {
    const response = await publicHttpClient.get('/portal/categories');
    return response.data;
  },

  
};