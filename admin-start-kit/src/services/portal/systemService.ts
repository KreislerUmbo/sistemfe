// src/services/systemService.ts
import httpClient from '@/helpers/http-client';
import type { AxiosResponse } from 'axios';
import type { System, SystemResponse, SystemsResponse } from '@/types/systems';

export const systemService = {
    async list(page = 1, search = ''): Promise<AxiosResponse<SystemsResponse>> {
        return httpClient.get(`systems?page=${page}&search=${search}`);
    },
    async store(data: FormData): Promise<AxiosResponse<SystemResponse>> {
        return httpClient.post('systems', data);
    },
    async update(id: number, data: FormData): Promise<AxiosResponse<SystemResponse>> {
        return httpClient.post(`systems/${id}`, data);
    },
    async delete(id: number): Promise<AxiosResponse> {
        return httpClient.delete(`systems/${id}`);
    },
    // ... otros métodos
};