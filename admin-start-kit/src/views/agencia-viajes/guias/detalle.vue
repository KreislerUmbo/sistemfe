<template>
    <DefaultLayout>
        <div v-if="guia" class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-hiking me-2 text-primary"></i>
                    {{ guia.nombre }}
                </h5>
                <small class="text-muted">{{ guia.documento }} — {{ guia.telefono }}</small>
            </div>
            <router-link to="/agencia-viajes/guias" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">1</span>
                <span class="fw-semibold text-dark">Nueva tarifa</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Destino (zona/lugar)</label>
                        <DestinoTreeSelect v-model="formTarifa.destino_id" nivel-min="zona" nivel-max="lugar" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Modalidad</label>
                        <select class="form-select form-select-sm" v-model="formTarifa.modalidad">
                            <option value="dia_local">Día local</option>
                            <option value="grupo_multidia">Grupo multidía</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Costo diario</label>
                        <input type="number" step="0.01" class="form-control form-control-sm" v-model.number="formTarifa.costo_diario">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Margen</label>
                        <div class="input-group input-group-sm">
                            <select class="form-select" style="max-width:90px" v-model="formTarifa.tipo_margen">
                                <option value="porcentaje">%</option>
                                <option value="fijo">S/</option>
                            </select>
                            <input type="number" step="0.01" class="form-control" v-model.number="formTarifa.margen_valor">
                        </div>
                    </div>
                    <div class="col-6 col-md-1">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                        <select class="form-select form-select-sm" v-model="formTarifa.moneda">
                            <option value="PEN">PEN</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Vigente desde</label>
                        <input type="date" class="form-control form-control-sm" v-model="formTarifa.vigente_desde">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Vigente hasta</label>
                        <input type="date" class="form-control form-control-sm" v-model="formTarifa.vigente_hasta">
                    </div>
                    <div class="col-12 col-md-2">
                        <button class="btn btn-primary btn-sm w-100" @click="guardarTarifa">
                            <i class="fas fa-plus me-1"></i>Agregar tarifa
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
                                <th class="ps-3">Destino</th>
                                <th>Modalidad</th>
                                <th class="text-end">Costo diario</th>
                                <th class="text-center">Vigencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="tarifas.length === 0">
                                <td colspan="4" class="text-center py-4 text-muted fst-italic">Sin tarifas cargadas.</td>
                            </tr>
                            <tr v-for="tarifa in tarifas" :key="tarifa.id">
                                <td class="ps-3">{{ tarifa.destino?.nombre }}</td>
                                <td>{{ tarifa.modalidad === 'dia_local' ? 'Día local' : 'Grupo multidía' }}</td>
                                <td class="text-end">{{ tarifa.moneda }} {{ tarifa.costo_diario }}</td>
                                <td class="text-center small">{{ formatFecha(tarifa.vigente_desde) }} — {{ tarifa.vigente_hasta ? formatFecha(tarifa.vigente_hasta) : 'indefinido' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { guiaService } from '@/services/admin/guiaService';
import { formatFecha } from '@/helpers/fecha';
import type { Guia, GuiaTarifa } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const guiaId = computed(() => Number(route.params.id));

const guia = ref<Guia | null>(null);
const tarifas = ref<GuiaTarifa[]>([]);

const formTarifa = ref<Partial<Omit<GuiaTarifa, 'destino_id'>> & { destino_id: number | null }>({
    destino_id: null, modalidad: 'dia_local', costo_diario: 0,
    tipo_margen: 'porcentaje', margen_valor: 0, moneda: 'PEN',
    vigente_desde: new Date().toISOString().slice(0, 10), vigente_hasta: null,
});

const cargar = async () => {
    const res = await guiaService.obtener(guiaId.value);
    guia.value = res.guia;
    const tarifasRes = await guiaService.listarTarifas(guiaId.value);
    tarifas.value = tarifasRes.guia_tarifas;
};

const guardarTarifa = async () => {
    if (!formTarifa.value.destino_id) {
        (Swal as TVueSwalInstance).fire('Error', 'Selecciona un destino.', 'error');
        return;
    }
    try {
        const res = await guiaService.crearTarifa(guiaId.value, formTarifa.value);
        await (Swal as TVueSwalInstance).fire('Listo', res.message, 'success');
        formTarifa.value = {
            destino_id: null as any, modalidad: 'dia_local', costo_diario: 0,
            tipo_margen: 'porcentaje', margen_valor: 0, moneda: 'PEN',
            vigente_desde: new Date().toISOString().slice(0, 10), vigente_hasta: null,
        };
        await cargar();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar', 'error');
    }
};

onMounted(() => cargar());
</script>
