<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-truck-loading me-2 text-primary"></i>
                    Proveedores
                </h5>
                <small class="text-muted">{{ totalPages }} registro(s) encontrado(s)</small>
            </div>
            <router-link to="/agencia-viajes/proveedores/nuevo" class="btn btn-primary fw-semibold shadow-sm">
                <i class="fas fa-plus me-2"></i>Nuevo Proveedor
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="fw-semibold text-secondary small text-uppercase mb-2">Tipos de proveedor habilitados</h6>
                <p class="text-muted small mb-2">
                    Solo los tipos habilitados acá aparecen como opción al crear un proveedor nuevo.
                    No afecta a los proveedores que ya existen de un tipo que deshabilites.
                </p>
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check form-switch" v-for="tipo in proveedorTipos" :key="tipo.id">
                        <input class="form-check-input" type="checkbox" role="switch" :id="`tipo-${tipo.id}`"
                            :checked="tipo.habilitado" :disabled="togglingId === tipo.id" @change="onToggleTipo(tipo)">
                        <label class="form-check-label small" :for="`tipo-${tipo.id}`">{{ tipo.nombre }}</label>
                    </div>
                    <span v-if="proveedorTipos.length === 0" class="text-muted small fst-italic">
                        No hay tipos de proveedor disponibles todavía.
                    </span>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Buscar</label>
                        <input type="text" class="form-control form-control-sm" placeholder="Razón social o nombre comercial..."
                            v-model="search" @keyup.enter="list">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo</label>
                        <select class="form-select form-select-sm" v-model="tipoId" @change="list">
                            <option :value="null">— Todos —</option>
                            <option v-for="tipo in proveedorTipos" :key="tipo.id" :value="tipo.id">{{ tipo.nombre }}</option>
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
                                <th class="ps-3">Razón Social</th>
                                <th>Tipo</th>
                                <th>Contacto</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
                                </td>
                            </tr>
                            <tr v-else-if="proveedores.length === 0">
                                <td colspan="5" class="text-center py-5 text-muted fst-italic">
                                    <i class="fas fa-inbox opacity-50 fs-4 mb-2 d-block"></i>
                                    No se encontraron proveedores.
                                </td>
                            </tr>
                            <tr v-for="proveedor in proveedores" :key="proveedor.id">
                                <td class="ps-3">
                                    <div class="fw-semibold">{{ proveedor.razon_social }}</div>
                                    <small class="text-muted" v-if="proveedor.nombre_comercial">{{ proveedor.nombre_comercial }}</small>
                                </td>
                                <td>{{ nombreTipo(proveedor.tipo_id) }}</td>
                                <td>
                                    <small class="text-muted d-block" v-if="proveedor.telefono">
                                        <i class="fas fa-phone me-1"></i>{{ proveedor.telefono }}
                                    </small>
                                    <small class="text-muted d-block" v-if="proveedor.email">
                                        <i class="fas fa-envelope me-1"></i>{{ proveedor.email }}
                                    </small>
                                </td>
                                <td class="text-center">
                                    <span class="badge" :class="proveedor.estado ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border'">
                                        {{ proveedor.estado ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="text-center pe-3">
                                    <router-link :to="`/agencia-viajes/proveedores/${proveedor.id}`" class="btn btn-sm btn-outline-primary me-1" title="Ver detalle">
                                        <i class="fas fa-eye"></i>
                                    </router-link>
                                    <router-link :to="`/agencia-viajes/proveedores/${proveedor.id}/editar`" class="btn btn-sm btn-outline-secondary me-1" title="Editar">
                                        <i class="fas fa-pen"></i>
                                    </router-link>
                                    <button class="btn btn-sm btn-outline-danger" @click="eliminar(proveedor)" title="Eliminar">
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
                <li class="page-item" :class="{ disabled: proveedores.length < perPageRows }">
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
import { proveedorService, proveedorTipoService } from '@/services/admin/proveedorService';
import type { Proveedor, ProveedorTipo } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const proveedores = ref<Proveedor[]>([]);
const proveedorTipos = ref<ProveedorTipo[]>([]);
const search = ref<string>('');
const tipoId = ref<number | null>(null);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(15);
const loading = ref<boolean>(false);
const togglingId = ref<number | null>(null);

const nombreTipo = (tipoId: number) => proveedorTipos.value.find((t) => t.id === tipoId)?.nombre ?? '—';

const onToggleTipo = async (tipo: ProveedorTipo) => {
    togglingId.value = tipo.id;
    try {
        const res = await proveedorTipoService.toggle(tipo.id);
        tipo.habilitado = res.proveedor_tipo.habilitado;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar el tipo de proveedor', 'error');
    } finally {
        togglingId.value = null;
    }
};

const list = async () => {
    loading.value = true;
    try {
        const res = await proveedorService.listar({
            page: currentPage.value,
            search: search.value || undefined,
            tipo_id: tipoId.value || undefined,
        });
        proveedores.value = res.proveedores;
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
    tipoId.value = null;
    currentPage.value = 1;
    list();
};

const eliminar = (proveedor: Proveedor) => {
    (Swal as TVueSwalInstance).fire({
        title: 'Confirmar eliminación',
        text: `¿Eliminar el proveedor "${proveedor.razon_social}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Sí, eliminar',
    }).then(async (result: any) => {
        if (!result.isConfirmed) return;
        try {
            const res = await proveedorService.eliminar(proveedor.id);
            (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
            list();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar', 'error');
        }
    });
};

onMounted(async () => {
    const res = await proveedorTipoService.listar();
    proveedorTipos.value = res.proveedor_tipos;
    list();
});
</script>
