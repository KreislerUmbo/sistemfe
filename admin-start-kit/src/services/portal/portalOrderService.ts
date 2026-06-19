import publicHttpClient from '@/helpers/publicHttpClient';

export const ordenService = {
    async listarPedidos(page = 1) {
        const response = await publicHttpClient.get('/portal/orders', { params: { page } });
        return response.data;
    },
    async obtenerPedido(id: number) {
        const response = await publicHttpClient.get(`/portal/orders/${id}`);
        return response.data;
    },
   
};