<template>
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Promover a proveedor real</h6>
                    <button class="btn-close" @click="$emit('close')"></button>
                </div>
                <div class="modal-body">
                    <div v-if="!item.proveedor_sugerido_manual" class="text-muted small mb-2 fst-italic">
                        Ítem manual original: "{{ item.descripcion_manual }}"
                    </div>
                    <small class="text-muted d-block mb-3">
                        Esto crea un proveedor real en tu catálogo — esta cotización no cambia, el proveedor queda
                        disponible para próximas cotizaciones.
                    </small>

                    <div class="row g-2 mb-2">
                        <div class="col-12">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Nombre del proveedor</label>
                            <input type="text" class="form-control form-control-sm" v-model="form.razon_social">
                        </div>
                        <div class="col-4">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Documento</label>
                            <select class="form-select form-select-sm" v-model="form.tipo_documento">
                                <option value="">—</option>
                                <option value="DNI">DNI</option>
                                <option value="RUC">RUC</option>
                            </select>
                        </div>
                        <div class="col-8">
                            <label class="form-label mb-1 small fw-semibold text-secondary">N° documento</label>
                            <input type="text" class="form-control form-control-sm" v-model="form.numero_documento">
                        </div>
                    </div>

                    <div class="mb-2">
                        <DestinoServicioPicker @seleccionado="(id) => (form.destino_servicio_id = id)" />
                        <div v-if="form.destino_servicio_id" class="small text-success mt-1">
                            <i class="fas fa-check me-1"></i>Servicio/destino seleccionado.
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Costo</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">{{ item.moneda_costo }}</span>
                                <input type="number" step="0.01" min="0" class="form-control" v-model.number="form.costo">
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Precio venta adulto</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">{{ item.moneda_costo }}</span>
                                <input type="number" step="0.01" min="0" class="form-control" v-model.number="form.precio_venta_adulto">
                            </div>
                        </div>
                    </div>
                    <div class="small text-muted mb-2">
                        Margen: <strong :class="margen < 0 ? 'text-danger' : 'text-success'">{{ item.moneda_costo }} {{ margen.toFixed(2) }}</strong>
                    </div>

                    <div class="mb-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary d-block">Modalidad</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" id="modalidad-compartido" value="compartido" v-model="form.modalidad">
                            <label class="form-check-label small" for="modalidad-compartido">Compartido</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" id="modalidad-privado" value="privado" v-model="form.modalidad">
                            <label class="form-check-label small" for="modalidad-privado">Privado</label>
                        </div>
                    </div>

                    <div class="border rounded">
                        <button type="button" class="btn btn-sm btn-light w-100 text-start" @click="mostrarDefaults = !mostrarDefaults">
                            <i class="fas fa-chevron-right me-1" :class="{ 'fa-rotate-90': mostrarDefaults }"></i>Ver valores por defecto
                        </button>
                        <div v-if="mostrarDefaults" class="p-2 small text-muted">
                            <div>Moneda: {{ item.moneda_costo }}</div>
                            <div>Vigente desde: hoy</div>
                            <div>Destino tributario: Nacional</div>
                            <div>Tipo de tarifa: Pública</div>
                            <div>Afectación IGV: 10 · Gravado</div>
                            <div class="fst-italic mt-1">Si hace falta editar estos valores, hacelo después desde el catálogo de Proveedores.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-secondary" @click="$emit('close')">Cancelar</button>
                    <button class="btn btn-sm btn-primary" :disabled="!puedeConfirmar || guardando" @click="confirmar">
                        <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>Crear proveedor
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Sesión 11q — "promover a proveedor real" a partir de un ítem manual. La
// cotización actual NO cambia (sin relink retroactivo, decisión confirmada
// con el usuario) — ver AlternativaItemController::promoverAProveedor().
import { ref, computed } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DestinoServicioPicker from '@/components/AgenciaViajes/DestinoServicioPicker.vue';
import { alternativaItemService } from '@/services/admin/alternativaItemService';
import type { AlternativaItem } from '@/types/agencia-viajes';

const props = defineProps<{ item: AlternativaItem }>();
const emit = defineEmits<{ (e: 'promovido', payload: any): void; (e: 'close'): void }>();

const form = ref({
    razon_social: props.item.proveedor_sugerido_manual ?? '',
    tipo_documento: '' as '' | 'DNI' | 'RUC',
    numero_documento: '',
    destino_servicio_id: null as number | null,
    costo: Number(props.item.costo_snapshot ?? 0),
    precio_venta_adulto: Number(props.item.precio_venta_snapshot),
    modalidad: '' as '' | 'compartido' | 'privado',
});

const mostrarDefaults = ref(false);
const guardando = ref(false);

const margen = computed(() => Math.round((form.value.precio_venta_adulto - form.value.costo) * 100) / 100);

const puedeConfirmar = computed(() => !!form.value.razon_social.trim()
    && !!form.value.destino_servicio_id
    && !!form.value.modalidad
    && form.value.costo >= 0
    && form.value.precio_venta_adulto >= 0);

const confirmar = async () => {
    if (!puedeConfirmar.value) return;
    guardando.value = true;
    try {
        const res = await alternativaItemService.promoverAProveedor(props.item.id, {
            razon_social: form.value.razon_social,
            tipo_documento: form.value.tipo_documento || undefined,
            numero_documento: form.value.numero_documento || undefined,
            destino_servicio_id: form.value.destino_servicio_id!,
            costo: form.value.costo,
            precio_venta_adulto: form.value.precio_venta_adulto,
            modalidad: form.value.modalidad as 'compartido' | 'privado',
        });
        emit('promovido', res);
    } catch (error: any) {
        Swal.fire('Error', error.response?.data?.message ?? 'No se pudo crear el proveedor', 'error');
    } finally {
        guardando.value = false;
    }
};
</script>
