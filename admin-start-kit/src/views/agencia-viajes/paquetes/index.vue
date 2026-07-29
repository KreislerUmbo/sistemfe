<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-suitcase-rolling me-2 text-primary"></i>
                    Paquetes / Tours
                </h5>
                <small class="text-muted">{{ totalPages }} registro(s) encontrado(s)</small>
            </div>
            <router-link to="/agencia-viajes/paquetes/nuevo" class="btn btn-primary fw-semibold shadow-sm">
                <i class="fas fa-plus me-2"></i>Nuevo Paquete/Tour
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Buscar</label>
                        <input type="text" class="form-control form-control-sm" placeholder="Nombre o código..."
                            v-model="search" @keyup.enter="list">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Categoría</label>
                        <select class="form-select form-select-sm" v-model="categoria" @change="list">
                            <option :value="null">— Todas —</option>
                            <option value="local">Local</option>
                            <option value="nacional">Nacional</option>
                            <option value="internacional">Internacional</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 d-flex gap-2">
                        <button class="btn btn-primary btn-sm w-100" @click="list">
                            <i class="fas fa-search me-1"></i>Buscar
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" @click="reset" title="Limpiar">
                            <i class="fas fa-undo"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">Nombre</th>
                                <th>Categoría</th>
                                <th>Destino</th>
                                <th class="text-end">Precio desde</th>
                                <th class="text-center">Web</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
                                </td>
                            </tr>
                            <tr v-else-if="paquetes.length === 0">
                                <td colspan="6" class="text-center py-5 text-muted fst-italic">
                                    <i class="fas fa-inbox opacity-50 fs-4 mb-2 d-block"></i>
                                    No se encontraron paquetes/tours.
                                </td>
                            </tr>
                            <tr v-for="paquete in paquetes" :key="paquete.id">
                                <td class="ps-3">
                                    <div class="fw-semibold">{{ paquete.nombre }}</div>
                                    <small class="text-muted" v-if="paquete.codigo">{{ paquete.codigo }}</small>
                                </td>
                                <td><span class="badge bg-light text-dark border">{{ etiquetaCategoria(paquete.categoria) }}</span></td>
                                <td>{{ paquete.destino_atractivo?.nombre ?? '—' }}</td>
                                <td class="text-end">{{ paquete.precio_venta_final != null ? `desde S/ ${Number(paquete.precio_venta_final).toFixed(0)}` : '—' }}</td>
                                <td class="text-center">
                                    <span class="badge" :class="paquete.publicado_web ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border'">
                                        {{ paquete.publicado_web ? 'Sí' : 'No' }}
                                    </span>
                                </td>
                                <td class="text-center pe-3">
                                    <router-link :to="`/agencia-viajes/paquetes/${paquete.id}`" class="btn btn-sm btn-outline-primary me-1" title="Ver detalle">
                                        <i class="fas fa-eye"></i>
                                    </router-link>
                                    <router-link :to="`/agencia-viajes/paquetes/${paquete.id}/editar`" class="btn btn-sm btn-outline-secondary me-1" title="Editar">
                                        <i class="fas fa-pen"></i>
                                    </router-link>
                                    <button class="btn btn-sm btn-outline-danger" @click="eliminar(paquete)" title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <nav v-if="totalPages > perPageRows" class="mt-3 d-flex justify-content-end">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item" :class="{ disabled: currentPage === 1 }">
                    <button class="page-link" @click="currentPage > 1 && (currentPage--, list())">Anterior</button>
                </li>
                <li class="page-item disabled"><span class="page-link">Página {{ currentPage }}</span></li>
                <li class="page-item" :class="{ disabled: paquetes.length < perPageRows }">
                    <button class="page-link" @click="currentPage++, list()">Siguiente</button>
                </li>
            </ul>
        </nav>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { paquetePlantillaService } from '@/services/admin/paquetePlantillaService';
import type { PaquetePlantilla } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const paquetes = ref<PaquetePlantilla[]>([]);
const search = ref<string>('');
const categoria = ref<string | null>(null);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(15);
const loading = ref<boolean>(false);

const etiquetaCategoria = (c: string) => ({ local: 'Local', nacional: 'Nacional', internacional: 'Internacional' } as Record<string, string>)[c] ?? c;

const list = async () => {
    loading.value = true;
    try {
        const res = await paquetePlantillaService.listar({
            page: currentPage.value,
            search: search.value || undefined,
            categoria: categoria.value || undefined,
        });
        paquetes.value = res.paquetes_plantilla;
        totalPages.value = res.total;
        perPageRows.value = res.paginate;
    } catch (error) {
        console.log(error);
    } finally {
        loading.value = false;
    }
};

const reset = () => {
    search.value = '';
    categoria.value = null;
    currentPage.value = 1;
    list();
};

const eliminar = (paquete: PaquetePlantilla) => {
    (Swal as TVueSwalInstance).fire({
        title: 'Confirmar eliminación',
        text: `¿Eliminar el paquete/tour "${paquete.nombre}"? Se borran también su itinerario, ítems incluidos y matriz de hoteles.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Sí, eliminar',
    }).then(async (result: any) => {
        if (!result.isConfirmed) return;
        try {
            const res = await paquetePlantillaService.eliminar(paquete.id);
            (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
            list();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar', 'error');
        }
    });
};

onMounted(() => {
    list();
});
</script>
