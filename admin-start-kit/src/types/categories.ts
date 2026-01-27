// Definición del tipo para una categoría individual
export type Categorie = {
    id: number;
    title: string;
    imagen: string;
    state: number;
    created_at: string;
};

// Definición del tipo para la respuesta paginada de categorías desde la API
export type Categories = {
    total: number;
    paginate: number;
    categories: Categorie[];
};

// Definición del tipo para la respuesta al crear o actualizar una categoría 
//se usa en el archivo src/views/categories/index.vue
export type CategorieResponse = {
    message: string;
    code: number;
    categorie?: Categorie;
};