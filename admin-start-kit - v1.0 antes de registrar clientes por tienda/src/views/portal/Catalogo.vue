<template>
    <div class="container-fluid py-4">
        <div class="row">
            <!-- Sidebar de filtros -->
            <div class="col-lg-3 mb-4">
                <div class="card bg-dark text-white border-secondary">
                    <div class="card-header bg-black border-secondary">
                        <h5 class="mb-0 fw-bold">Filtrar productos</h5>
                    </div>
                    <div class="card-body">
                        <!-- Categorías (desde API) -->
                        <div class="mb-4">
                            <h6 class="fw-bold text-danger">CATEGORÍA</h6>
                            <div class="form-check" v-for="cat in categoriasDisponibles" :key="cat.id">
                                <input class="form-check-input bg-dark border-secondary" type="checkbox" :value="cat.id"
                                    v-model="filtros.categorias" :id="'cat' + cat.id">
                                <label class="form-check-label text-white-50" :for="'cat' + cat.id">{{ cat.title
                                    }}</label>
                            </div>
                        </div>

                        <!-- Rango de precio -->
                        <div class="mb-4">
                            <h6 class="fw-bold text-danger">PRECIO (S/)</h6>
                            <div class="d-flex gap-2 align-items-center">
                                <span>S/ 0</span>
                                <input type="range" class="form-range bg-danger" min="0" max="5000" step="50"
                                    v-model="filtros.precioMax">
                                <span>S/ {{ filtros.precioMax }}</span>
                            </div>
                        </div>

                        <!-- Marcas (mock - opcional) -->
                        <div class="mb-4">
                            <h6 class="fw-bold text-danger">MARCA</h6>
                            <div class="form-check" v-for="marca in marcas" :key="marca">
                                <input class="form-check-input bg-dark border-secondary" type="checkbox" :value="marca"
                                    v-model="filtros.marcas" :id="'marca' + marca">
                                <label class="form-check-label text-white-50" :for="'marca' + marca">{{ marca }}</label>
                            </div>
                        </div>

                        <!-- Disponibilidad -->
                        <div class="mb-4">
                            <h6 class="fw-bold text-danger">DISPOSIBILIDAD</h6>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="stock"
                                    v-model="filtros.disponibilidad" id="stock">
                                <label class="form-check-label text-white-50" for="stock">En stock</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="envioGratis"
                                    v-model="filtros.disponibilidad" id="envio">
                                <label class="form-check-label text-white-50" for="envio">Envío gratis</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="oferta"
                                    v-model="filtros.disponibilidad" id="oferta">
                                <label class="form-check-label text-white-50" for="oferta">En oferta</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="digital"
                                    v-model="filtros.disponibilidad" id="digital">
                                <label class="form-check-label text-white-50" for="digital">Digital / descarga</label>
                            </div>
                        </div>

                        <!-- Valoración -->
                        <div class="mb-4">
                            <h6 class="fw-bold text-danger">VALORACIÓN</h6>
                            <div class="form-check" v-for="rating in ratings" :key="rating.valor">
                                <input class="form-check-input" type="radio" name="rating" :value="rating.valor"
                                    v-model="filtros.ratingMin">
                                <label class="form-check-label text-white-50">{{ rating.estrellas }} y más ({{
                                    rating.cantidad }})</label>
                            </div>
                        </div>

                        <button class="btn btn-outline-danger w-100 mt-2" @click="limpiarFiltros">Limpiar
                            filtros</button>
                    </div>
                </div>
            </div>

            <!-- Listado de productos -->
            <div class="col-lg-9">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                    <p class="text-white-50 mb-0">Mostrando <strong class="text-white">{{ productos.length }}</strong>
                        productos</p>
                    <select class="form-select w-auto bg-dark text-white border-secondary" v-model="orden">
                        <option value="default">Ordenar por</option>
                        <option value="price_asc">Precio: menor a mayor</option>
                        <option value="price_desc">Precio: mayor a menor</option>
                        <option value="name_asc">Nombre: A-Z</option>
                    </select>
                </div>

                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-danger" role="status"></div>
                </div>

                <div v-else-if="productos.length === 0" class="alert alert-info">
                    No se encontraron productos con esos filtros.
                </div>

                <div class="row g-4" v-else>
                    <div v-for="producto in productos" :key="producto.id" class="col-md-6 col-lg-4">
                        <div class="card bg-dark text-white border-secondary h-100 position-relative shadow-sm">
                            <div v-if="producto.descuento" class="position-absolute top-0 start-0 m-2 z-1">
                                <span class="badge bg-danger rounded-pill">-{{ producto.descuento }}%</span>
                            </div>
                            <div v-if="producto.popular" class="position-absolute top-0 end-0 m-2 z-1">
                                <span class="badge bg-warning text-dark rounded-pill">Popular</span>
                            </div>
                            <img :src="producto.imagen" class="card-img-top" :alt="producto.nombre"
                                style="width: 100%; height: 300px; ">
                            <div class="card-body d-flex flex-column">
                                <span class="badge bg-secondary mb-2 align-self-start">{{ producto.categoria }}</span>
                                <h6 class="card-title fw-bold">{{ producto.nombre }}</h6>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div class="text-warning small">
                                        <i v-for="n in Math.floor(producto.rating)" :key="n"
                                            class="bi bi-star-fill"></i>
                                        <i v-if="producto.rating % 1 !== 0" class="bi bi-star-half"></i>
                                        <i v-for="n in (5 - Math.ceil(producto.rating))" :key="n + 100"
                                            class="bi bi-star"></i>
                                    </div>
                                    <span class="text-white-50 small">({{ producto.ratingCount }})</span>
                                </div>
                                <div class="mb-2">
                                    <span v-if="producto.precioOriginal"
                                        class="text-decoration-line-through text-white-50 me-2">S/ {{
                                            producto.precioOriginal.toFixed(2) }}</span>
                                    <span class="text-danger fw-bold fs-5">S/ {{ producto.precio.toFixed(2) }}</span>
                                </div>
                                <div v-if="producto.stock <= 3" class="alert alert-danger py-1 px-2 small mb-2">
                                    ¡Solo {{ producto.stock }} restantes! — Stock limitado
                                </div>
                                <!-- Botones de acciones agregar/quitar -->
                                <div class="mt-auto d-flex gap-2">
                                    <button class="btn btn-outline-danger btn-sm flex-grow-1"
                                        @click="verDetalle(producto.id)">Ver</button>
                                    <button v-if="!carritoStore.isInCart(producto.id)"
                                        class="btn btn-danger btn-sm flex-grow-1"
                                        @click="agregarAlCarrito(producto)">Agregar</button>
                                    <button v-else class="btn btn-outline-danger btn-sm flex-grow-1"
                                        @click="quitarDelCarrito(producto.id)">Quitar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Paginación -->
                <nav v-if="totalPaginas > 1" class="mt-5">
                    <ul class="pagination justify-content-center">
                        <li class="page-item" :class="{ disabled: paginaActual === 1 }">
                            <button class="page-link bg-dark text-white border-secondary"
                                @click="paginaActual--">Anterior</button>
                        </li>
                        <li class="page-item" v-for="p in totalPaginas" :key="p"
                            :class="{ active: p === paginaActual }">
                            <button class="page-link bg-dark text-white border-secondary" @click="paginaActual = p">{{ p
                                }}</button>
                        </li>
                        <li class="page-item" :class="{ disabled: paginaActual === totalPaginas }">
                            <button class="page-link bg-dark text-white border-secondary"
                                @click="paginaActual++">Siguiente</button>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useRoute } from 'vue-router';
import { useCarritoStore } from '@/stores/carrito'
import { productoService, type ProductoPortal, type CategoriaPortal } from '@/services/portal/productoService'
import Swal from 'sweetalert2'


const router = useRouter()
const carritoStore = useCarritoStore()
const route = useRoute();
// Estado reactivo
const productos = ref<ProductoPortal[]>([])
const categoriasDisponibles = ref<CategoriaPortal[]>([])
const loading = ref(false)
const paginaActual = ref(1)
const totalPaginas = ref(1)

// Filtros (solo los que usaremos con la API real)
const filtros = ref({
    categorias: [] as number[],      // ids de categorías
    precioMax: 5000,
    marcas: [] as string[],
    disponibilidad: [] as string[],
    ratingMin: 0,
    search: ''
})
const orden = ref('default')

// Datos estáticos para filtros que aún no vienen del API
const marcas = ['HP', 'Lenovo', 'Asus', 'Dell', 'Acer', 'Samsung', 'Apple', 'Redragon', 'Logitech', 'Kaspersky', 'Epson', 'Xiaomi']
const ratings = [
    { valor: 0, estrellas: '☆☆☆☆☆', cantidad: 42 },
    { valor: 1, estrellas: '★☆☆☆☆', cantidad: 31 },
    { valor: 2, estrellas: '★★☆☆☆', cantidad: 18 },
    { valor: 3, estrellas: '★★★☆☆', cantidad: 7 }
]

// Cargar productos desde la API
const cargarProductos = async () => {
    loading.value = true
    try {
        const params: any = {
            page: paginaActual.value,
            per_page: 9,
            search: filtros.value.search,
            categorie_id: filtros.value.categorias.join(','),
            max_price: filtros.value.precioMax,
            order_by: orden.value === 'price_asc' ? 'price_asc' : (orden.value === 'price_desc' ? 'price_desc' : 'default')
        }
        if (filtros.value.disponibilidad.includes('stock')) params.in_stock = true

        const res = await productoService.listar(params)
        productos.value = res.products.data
        totalPaginas.value = res.products.last_page

    } catch (error) {
        console.error(error)
    } finally {
        loading.value = false
    }
}

// Cargar categorías para el filtro
const cargarCategorias = async () => {
    try {
        const cats = await productoService.obtenerCategorias()
        categoriasDisponibles.value = cats
    } catch (error) {
        console.error(error)
    }
}

// Observar cambios en filtros y orden para recargar (resetear página)
watch([filtros, orden], () => {
    paginaActual.value = 1
    cargarProductos()
}, { deep: true })

// Observar cambio de página
watch(paginaActual, () => {
    cargarProductos()
})

// Limpiar todos los filtros
const limpiarFiltros = () => {
    filtros.value = {
        categorias: [],
        precioMax: 5000,
        marcas: [],
        disponibilidad: [],
        ratingMin: 0,
        search: ''
    }
    orden.value = 'default'
    paginaActual.value = 1
}

// Agregar al carrito
const agregarAlCarrito = (producto: ProductoPortal) => {
    carritoStore.agregarProducto({
        id: producto.id,
        nombre: producto.nombre,
        precio: producto.precio,
        imagen: producto.imagen,
        cantidad: 1
    })
    // mostrar un toast o notificación

}


const quitarDelCarrito = (id: number) => {
  carritoStore.eliminarProducto(id)
  // Opcional: mostrar un toast o notificación
}

// Ver detalle
const verDetalle = (id: number) => {
    router.push(`productos/${id}`)
}




// Inicializar
onMounted(() => {
    cargarCategorias();
    // Inicializar filtro según query param
    const categorieId = route.query.categorie_id;
    if (categorieId) {
        filtros.value.categorias = [Number(categorieId)];
    } else {
        filtros.value.categorias = [];
    }
    cargarProductos();
});

// Watcher para capturar cambios en el query param 'categorie_id'
watch(() => route.query.categorie_id, (newId) => {
    if (newId) {
        // Si hay ID, filtrar por esa categoría
        filtros.value.categorias = [Number(newId)];
    } else {
        // Si no hay ID (ruta /tienda sin query), limpiar filtros de categorías
        filtros.value.categorias = [];
    }
    // Resetear página y recargar productos
    paginaActual.value = 1;
    cargarProductos();
});
</script>

<style scoped>
.form-check-input:checked {
    background-color: #dc3545;
    border-color: #dc3545;
}

.form-range::-webkit-slider-thumb {
    background: #dc3545;
}

.page-item.active .page-link {
    background-color: #dc3545;
    border-color: #dc3545;
}

.page-link {
    cursor: pointer;
}

.bi-star-fill,
.bi-star {
    font-size: 0.8rem;
}

.card-img-top {
    transition: transform 0.2s;
}

.card:hover .card-img-top {
    transform: scale(1.02);
}

.badge.bg-secondary {
    background-color: #6c757d !important;
}

.alert-danger {
    background-color: rgba(220, 53, 69, 0.2);
    border-color: #dc3545;
    color: #f8d7da;
}
</style>