import httpClient from '@/helpers/http-client';
import type { AxiosResponse } from 'axios';
import type { SystemCategory, SystemCategoriesResponse, SystemCategoryResponse } from '@/types/system_categories';

export const systemCategoryService = {
    async list(page = 1, search = ''): Promise<AxiosResponse<SystemCategoriesResponse>> {
        return httpClient.get(`system_categories?page=${page}&search=${search}`);
    },
    async store(data: FormData): Promise<AxiosResponse<SystemCategoryResponse>> {
        return httpClient.post('system_categories', data);
    },
    async update(id: number, data: FormData): Promise<AxiosResponse<SystemCategoryResponse>> {
        return httpClient.post(`system_categories/${id}`, data);
    },
    async delete(id: number): Promise<AxiosResponse> {
        return httpClient.delete(`system_categories/${id}`);
    },
};