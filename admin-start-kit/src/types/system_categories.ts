export interface SystemCategory {
    id: number;
    nombre: string;
    icon: string;
    imagen: string | null;
    state: number; // 1 activo, 2 inactivo
    created_at: string;
    display_order: number;
}

export interface SystemCategoriesResponse {
    total: number;
    paginate: number;
    categories: SystemCategory[];
}

export interface SystemCategoryResponse {
    code: number;
    message: string;
    categorie?: SystemCategory;
}