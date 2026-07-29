<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-hiking me-2 text-primary"></i>
                    Guías Turísticos
                </h5>
                <small class="text-muted">{{ totalPages }} registro(s) encontrado(s)</small>
            </div>
            <button class="btn btn-primary fw-semibold shadow-sm" @click="abrirFormNuevo">
                <i class="fas fa-plus me-2"></i>Nuevo Guía
            </button>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" placeholder="Buscar por nombre..." v-model="search" @keyup.enter="list">
                    <button class="btn btn-primary" @click="list"><i class="fas fa-search"></i></button>
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
                                <th>Documento</th>
                                <th>Teléfono</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="5" class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando...</td>
                            </tr>
                            <tr v-else-if="guias.length === 0">
                                <td colspan="5" class="text-center py-5 text-muted fst-italic">Sin guías registrados.</td>
                            </tr>
                            <tr v-for="guia in guias" :key="guia.id">
                                <td class="ps-3 fw-semibold">{{ guia.nombre }}</td>
                                <td>{{ guia.documento }}</td>
                                <td>{{ guia.telefono }}</td>
                                <td class="text-center">
                                    <span class="badge" :class="guia.activo ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border'">
                                        {{ guia.activo ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="text-center pe-3">
                                    <router-link :to="`/agencia-viajes/guias/${guia.id}`" class="btn btn-sm btn-outline-primary me-1" title="Tarifas">
                                        <i class="fas fa-dollar-sign"></i>
                                    </router-link>
                                    <button class="btn btn-sm btn-outline-secondary me-1" title="Editar" @click="abrirFormEditar(guia)">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" title="Eliminar" @click="eliminar(guia)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="modalFormAbierto" class="modal d-block" style="background:rgba(0,0,0,.5)" @click.self="modalFormAbierto = false">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">{{ guiaEditando ? 'Editar' : 'Nuevo' }} Guía</h6>
                        <button class="btn-close" @click="modalFormAbierto = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary">Nombre *</label>
                            <input type="text" class="form-control form-control-sm" v-model="formGuia.nombre">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary">Documento *</label>
                            <input type="text" class="form-control form-control-sm" v-model="formGuia.documento">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary">Teléfono *</label>
                            <input type="text" class="form-control form-control-sm" v-model="formGuia.telefono">
                        </div>
                        <div class="form-check form-switch" v-if="guiaEditando">
                            <input class="form-check-input" type="checkbox" id="guia-activo" v-model="formGuia.activo">
                            <label class="form-check-label" for="guia-activo">Activo</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" @click="modalFormAbierto = false">Cancelar</button>
                        <button class="btn btn-primary" @click="guardar">Guardar</button>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { guiaService } from '@/services/admin/guiaService';
import type { Guia } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const guias = ref<Guia[]>([]);
const search = ref<string>('');
const totalPages = ref<number>(0);
const loading = ref<boolean>(false);

const list = async () => {
    loading.value = true;
    try {
        const res = await guiaService.listar({ search: search.value || undefined });
        guias.value = res.guias;
        totalPages.value = res.total;
    } finally {
        loading.value = false;
    }
};

const modalFormAbierto = ref<boolean>(false);
const guiaEditando = ref<Guia | null>(null);
const formGuia = ref<Partial<Guia>>({ nombre: '', documento: '', telefono: '', activo: true });

const abrirFormNuevo = () => {
    guiaEditando.value = null;
    formGuia.value = { nombre: '', documento: '', telefono: '', activo: true };
    modalFormAbierto.value = true;
};

const abrirFormEditar = (guia: Guia) => {
    guiaEditando.value = guia;
    formGuia.value = { ...guia };
    modalFormAbierto.value = true;
};

const guardar = async () => {
    if (!formGuia.value.nombre?.trim() || !formGuia.value.documento?.trim() || !formGuia.value.telefono?.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'Nombre, documento y teléfono son obligatorios.', 'error');
        return;
    }
    try {
        const res = guiaEditando.value
            ? await guiaService.actualizar(guiaEditando.value.id, formGuia.value)
            : await guiaService.crear(formGuia.value);

        modalFormAbierto.value = false;
        await (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        await list();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    }
};

const eliminar = (guia: Guia) => {
    (Swal as TVueSwalInstance).fire({
        title: 'Confirmar eliminación', text: `¿Eliminar al guía "${guia.nombre}"?`, icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Sí, eliminar',
    }).then(async (result: any) => {
        if (!result.isConfirmed) return;
        try {
            const res = await guiaService.eliminar(guia.id);
            (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
            await list();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar', 'error');
        }
    });
};

onMounted(() => list());
</script>
