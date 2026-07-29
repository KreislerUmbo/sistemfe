<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-suitcase-rolling me-2 text-primary"></i>
                    {{ esEdicion ? 'Editar Paquete/Tour' : 'Nuevo Paquete/Tour' }}
                </h5>
                <small class="text-muted">Datos generales de la plantilla — itinerario, ítems incluidos y hoteles se cargan después de guardar</small>
            </div>
            <router-link to="/agencia-viajes/paquetes" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Código</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.codigo" placeholder="Ej. PDKM-CZ">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Categoría *</label>
                        <select class="form-select form-select-sm" v-model="form.categoria">
                            <option value="local">Local</option>
                            <option value="nacional">Nacional</option>
                            <option value="internacional">Internacional</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Nombre *</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.nombre" placeholder="Ej. Full Day Alto Mayo">
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Descripción</label>
                        <textarea class="form-control form-control-sm" rows="2" v-model="form.descripcion"></textarea>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Destino / Atractivo principal *</label>
                        <DestinoTreeSelect v-model="form.destino_atractivo_id" nivel-max="lugar" />
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Duración (horas) *</label>
                        <input type="number" min="1" class="form-control form-control-sm" v-model.number="form.duracion_horas">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Precio venta (desde)</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model.number="form.precio_venta_final" placeholder="Se resuelve solo con los hoteles">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hora de salida</label>
                        <input type="time" class="form-control form-control-sm" v-model="form.hora_salida">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hora de retorno</label>
                        <input type="time" class="form-control form-control-sm" v-model="form.hora_retorno">
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Lugar de recojo</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.lugar_recojo" placeholder="Ej. Hoteles ubicados dentro de la ciudad">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">No incluye</label>
                        <textarea class="form-control form-control-sm" rows="2" v-model="form.no_incluye"></textarea>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Recomendaciones</label>
                        <textarea class="form-control form-control-sm" rows="2" v-model="form.recomendaciones"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-semibold text-dark small">Vuelo</span>
            </div>
            <div class="card-body py-3">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="vueloIncluido" v-model="form.vuelo_incluido">
                    <label class="form-check-label small" for="vueloIncluido">Incluye vuelo (ej. Cusco/Panamá — Alto Mayo normalmente no)</label>
                </div>
                <div v-if="form.vuelo_incluido" class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Aerolínea</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.vuelo_aerolinea">
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Detalle (tramos, fechas, equipaje...)</label>
                        <input type="text" class="form-control form-control-sm" v-model="form.vuelo_detalle">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Vigente desde</label>
                        <input type="date" class="form-control form-control-sm" v-model="form.vigencia_desde">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Vigente hasta</label>
                        <input type="date" class="form-control form-control-sm" v-model="form.vigencia_hasta">
                    </div>
                    <div class="col-12 col-md-6 d-flex align-items-center">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="publicadoWeb" v-model="form.publicado_web">
                            <label class="form-check-label small text-muted" for="publicadoWeb">Publicado en portal web (sin efecto todavía — interruptor para el futuro portal)</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end mb-4">
            <button class="btn btn-primary fw-semibold" @click="guardar" :disabled="guardando">
                <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="fas fa-check me-2"></i>
                {{ esEdicion ? 'Guardar cambios' : 'Crear paquete/tour' }}
            </button>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import { paquetePlantillaService } from '@/services/admin/paquetePlantillaService';
import type { PaquetePlantilla } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const router = useRouter();

const esEdicion = computed(() => !!route.params.id);
const paqueteId = computed(() => Number(route.params.id));

const guardando = ref<boolean>(false);

const form = ref<Partial<PaquetePlantilla> & { destino_atractivo_id: number | null }>({
    codigo: null,
    categoria: 'local',
    nombre: '',
    descripcion: null,
    destino_atractivo_id: null,
    duracion_horas: 1,
    hora_salida: null,
    hora_retorno: null,
    lugar_recojo: null,
    no_incluye: null,
    recomendaciones: null,
    vuelo_incluido: false,
    vuelo_aerolinea: null,
    vuelo_detalle: null,
    precio_venta_final: null,
    vigencia_desde: null,
    vigencia_hasta: null,
    publicado_web: false,
});

const cargarPaquete = async () => {
    if (!esEdicion.value) return;
    try {
        const res = await paquetePlantillaService.obtener(paqueteId.value);
        form.value = { ...res.paquete_plantilla };
    } catch (error) {
        console.log(error);
    }
};

const guardar = async () => {
    if (!form.value.nombre?.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'El nombre es obligatorio.', 'error');
        return;
    }
    if (!form.value.destino_atractivo_id) {
        (Swal as TVueSwalInstance).fire('Error', 'Seleccioná el destino/atractivo principal.', 'error');
        return;
    }
    if (!form.value.duracion_horas || form.value.duracion_horas < 1) {
        (Swal as TVueSwalInstance).fire('Error', 'La duración en horas es obligatoria.', 'error');
        return;
    }

    guardando.value = true;
    try {
        const res = esEdicion.value
            ? await paquetePlantillaService.actualizar(paqueteId.value, form.value)
            : await paquetePlantillaService.crear(form.value);

        await (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        router.push(`/agencia-viajes/paquetes/${res.paquete_plantilla.id}`);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    } finally {
        guardando.value = false;
    }
};

onMounted(async () => {
    await cargarPaquete();
});
</script>
