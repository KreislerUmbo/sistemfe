<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-calendar-alt me-2 text-primary"></i>
                    Temporadas
                </h5>
                <small class="text-muted">Catálogo compartido por todo el rubro — cuidado al editar/eliminar</small>
            </div>
            <button class="btn btn-primary fw-semibold shadow-sm" @click="abrirFormNueva">
                <i class="fas fa-plus me-2"></i>Nueva Temporada
            </button>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">Nombre</th>
                                <th>Tipo</th>
                                <th>Ocurrencias</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="4" class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando...</td>
                            </tr>
                            <tr v-else-if="temporadas.length === 0">
                                <td colspan="4" class="text-center py-5 text-muted fst-italic">Sin temporadas cargadas.</td>
                            </tr>
                            <tr v-for="temporada in temporadas" :key="temporada.id">
                                <td class="ps-3 fw-semibold">{{ temporada.nombre }}</td>
                                <td>{{ temporada.tipo === 'fija' ? 'Fija (mismo rango cada año)' : 'Móvil (varía por año)' }}</td>
                                <td>
                                    <span v-if="!temporada.temporada_ocurrencias?.length" class="text-muted small fst-italic">Sin ocurrencias</span>
                                    <span v-for="oc in temporada.temporada_ocurrencias" :key="oc.id" class="badge bg-light text-dark border me-1">
                                        {{ oc.anio }}: {{ oc.fecha_desde }} — {{ oc.fecha_hasta }}
                                    </span>
                                </td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-success me-1" title="Nueva ocurrencia" @click="abrirFormOcurrencia(temporada)">
                                        <i class="fas fa-calendar-plus"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary me-1" title="Editar" @click="abrirFormEditar(temporada)">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" title="Eliminar" @click="eliminar(temporada)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal crear/editar temporada -->
        <div v-if="modalFormAbierto" class="modal d-block" style="background:rgba(0,0,0,.5)" @click.self="modalFormAbierto = false">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">{{ temporadaEditando ? 'Editar' : 'Nueva' }} Temporada</h6>
                        <button class="btn-close" @click="modalFormAbierto = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary">Nombre *</label>
                            <input type="text" class="form-control form-control-sm" v-model="formTemporada.nombre" placeholder="Ej. Fiestas Patrias">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary">Tipo *</label>
                            <select class="form-select form-select-sm" v-model="formTemporada.tipo">
                                <option value="fija">Fija (mismo rango cada año)</option>
                                <option value="movil">Móvil (varía por año)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" @click="modalFormAbierto = false">Cancelar</button>
                        <button class="btn btn-primary" @click="guardarTemporada">Guardar</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal nueva ocurrencia -->
        <div v-if="modalOcurrenciaAbierto" class="modal d-block" style="background:rgba(0,0,0,.5)" @click.self="modalOcurrenciaAbierto = false">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">Nueva ocurrencia — {{ temporadaOcurrenciaActiva?.nombre }}</h6>
                        <button class="btn-close" @click="modalOcurrenciaAbierto = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-4">
                                <label class="form-label small fw-semibold text-secondary">Año *</label>
                                <input type="number" class="form-control form-control-sm" v-model.number="formOcurrencia.anio">
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-semibold text-secondary">Desde *</label>
                                <input type="date" class="form-control form-control-sm" v-model="formOcurrencia.fecha_desde">
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-semibold text-secondary">Hasta *</label>
                                <input type="date" class="form-control form-control-sm" v-model="formOcurrencia.fecha_hasta">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" @click="modalOcurrenciaAbierto = false">Cancelar</button>
                        <button class="btn btn-primary" @click="guardarOcurrencia">Guardar</button>
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
import { temporadaService } from '@/services/admin/temporadaService';
import type { Temporada, TemporadaOcurrencia } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const temporadas = ref<Temporada[]>([]);
const loading = ref<boolean>(false);

const cargarTemporadas = async () => {
    loading.value = true;
    try {
        const res = await temporadaService.listar();
        temporadas.value = res.temporadas;
    } finally {
        loading.value = false;
    }
};

// ── Form crear/editar ────────────────────────────────────────────────
const modalFormAbierto = ref<boolean>(false);
const temporadaEditando = ref<Temporada | null>(null);
const formTemporada = ref<Partial<Temporada>>({ nombre: '', tipo: 'fija' });

const abrirFormNueva = () => {
    temporadaEditando.value = null;
    formTemporada.value = { nombre: '', tipo: 'fija' };
    modalFormAbierto.value = true;
};

const abrirFormEditar = (temporada: Temporada) => {
    temporadaEditando.value = temporada;
    formTemporada.value = { nombre: temporada.nombre, tipo: temporada.tipo };
    modalFormAbierto.value = true;
};

const guardarTemporada = async () => {
    if (!formTemporada.value.nombre?.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'El nombre es obligatorio.', 'error');
        return;
    }
    try {
        const res = temporadaEditando.value
            ? await temporadaService.actualizar(temporadaEditando.value.id, formTemporada.value)
            : await temporadaService.crear(formTemporada.value);

        modalFormAbierto.value = false;
        await (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        await cargarTemporadas();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    }
};

const eliminar = (temporada: Temporada) => {
    (Swal as TVueSwalInstance).fire({
        title: 'Confirmar eliminación', text: `¿Eliminar la temporada "${temporada.nombre}"? Esto afecta a TODO el rubro, no solo este negocio.`,
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Sí, eliminar',
    }).then(async (result: any) => {
        if (!result.isConfirmed) return;
        try {
            const res = await temporadaService.eliminar(temporada.id);
            (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
            await cargarTemporadas();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar', 'error');
        }
    });
};

// ── Ocurrencias ───────────────────────────────────────────────────────
const modalOcurrenciaAbierto = ref<boolean>(false);
const temporadaOcurrenciaActiva = ref<Temporada | null>(null);
const formOcurrencia = ref<Partial<TemporadaOcurrencia>>({ anio: new Date().getFullYear(), fecha_desde: '', fecha_hasta: '' });

const abrirFormOcurrencia = (temporada: Temporada) => {
    temporadaOcurrenciaActiva.value = temporada;
    formOcurrencia.value = { anio: new Date().getFullYear(), fecha_desde: '', fecha_hasta: '' };
    modalOcurrenciaAbierto.value = true;
};

const guardarOcurrencia = async () => {
    if (!temporadaOcurrenciaActiva.value) return;
    try {
        const res = await temporadaService.crearOcurrencia(temporadaOcurrenciaActiva.value.id, formOcurrencia.value);
        modalOcurrenciaAbierto.value = false;
        await (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        await cargarTemporadas();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    }
};

onMounted(() => {
    cargarTemporadas();
});
</script>
