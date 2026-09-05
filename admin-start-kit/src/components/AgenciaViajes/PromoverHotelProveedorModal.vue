<template>
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Promover "{{ hotel.nombre_hotel }}" a proveedor real</h6>
                    <button class="btn-close" @click="$emit('close')"></button>
                </div>
                <div class="modal-body">
                    <small class="text-muted d-block mb-3">
                        Esto crea un proveedor real en tu catálogo — con todos los tipos de habitación ya cargados
                        acá como tarifas suyas. Esta cotización no cambia; el proveedor queda disponible para
                        buscarlo (con "Tarifa registrada") la próxima vez que agregues un hotel de mayorista.
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
// 05-sep-2026 — "promover a proveedor real" para un hotel ad-hoc del
// comparador de mayoristas (opcion_mayorista_id no nulo, proveedor_id nulo).
// Mismo molde visual que PromoverProveedorModal.vue (ítems manuales), pero
// SIN costo/venta/modalidad: OpcionHotelController::promover() ya toma esos
// valores de las OpcionHotelTarifa existentes del hotel (una tarifa real por
// cada tipo de habitación ya cargado) — acá solo faltan los datos del
// proveedor en sí. La cotización actual NO cambia (sin relink retroactivo,
// mismo criterio que el otro promover).
import { ref, computed } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DestinoServicioPicker from '@/components/AgenciaViajes/DestinoServicioPicker.vue';
import { opcionHotelService } from '@/services/admin/opcionHotelService';

const props = defineProps<{ hotel: { id: number; nombre_hotel: string } }>();
const emit = defineEmits<{ (e: 'promovido', payload: any): void; (e: 'close'): void }>();

const form = ref({
    razon_social: props.hotel.nombre_hotel,
    tipo_documento: '' as '' | 'DNI' | 'RUC',
    numero_documento: '',
    destino_servicio_id: null as number | null,
});

const guardando = ref(false);

const puedeConfirmar = computed(() => !!form.value.razon_social.trim() && !!form.value.destino_servicio_id);

const confirmar = async () => {
    if (!puedeConfirmar.value) return;
    guardando.value = true;
    try {
        const res = await opcionHotelService.promover(props.hotel.id, {
            razon_social: form.value.razon_social,
            tipo_documento: form.value.tipo_documento || undefined,
            numero_documento: form.value.numero_documento || undefined,
            destino_servicio_id: form.value.destino_servicio_id!,
        });
        emit('promovido', res);
    } catch (error: any) {
        Swal.fire('Error', error.response?.data?.message ?? 'No se pudo crear el proveedor', 'error');
    } finally {
        guardando.value = false;
    }
};
</script>
