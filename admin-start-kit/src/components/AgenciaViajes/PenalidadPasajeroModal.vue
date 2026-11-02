<template>
    <Teleport to="body">
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" @click.self="$emit('cerrar')">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title">
                            <i class="fas fa-user-minus me-2 text-danger"></i>Penalidad por quitar {{ pasajero.nombre ? `a ${pasajero.nombre}` : 'un pasajero' }}
                        </h6>
                        <button type="button" class="btn-close" @click="$emit('cerrar')"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted mb-3">
                            Cada pasajero que se quita lleva su penalidad (puede ser 0). Se suma como servicio de la reserva, sin pasajeros asignados.
                        </p>
                        <div class="row g-2 mb-2">
                            <div class="col-5">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Monto ({{ moneda }})</label>
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm" v-model="monto">
                            </div>
                            <div class="col-7">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Motivo</label>
                                <input type="text" maxlength="400" class="form-control form-control-sm" v-model="motivo" placeholder="Ej. cancelación fuera de plazo">
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Tratamiento de IGV</label>
                                <select class="form-select form-select-sm" v-model="tipAfeIgv">
                                    <option value="" disabled>Elige…</option>
                                    <option value="10">Gravado</option>
                                    <option value="20">Exonerado</option>
                                    <option value="30">Inafecto</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                                <select class="form-select form-select-sm" v-model="destino">
                                    <option value="" disabled>Elige…</option>
                                    <option value="nacional">Nacional</option>
                                    <option value="amazonia">Amazonía</option>
                                    <option value="extranjero">Extranjero</option>
                                </select>
                            </div>
                        </div>
                        <small class="text-muted d-block">El tratamiento de IGV no tiene valor por defecto: lo eliges tú.</small>
                        <div v-if="errorMensaje" class="alert alert-danger py-2 small mt-3 mb-0">{{ errorMensaje }}</div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="$emit('cerrar')">Cancelar</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="!esValido || guardando" @click="guardar">
                            <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                            Guardar penalidad
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import type { PenalidadPayload } from '@/services/admin/reservaModificacionService';

// Formulario de la penalidad de un pasajero quitado (R-AGE-05). El motivo se muestra siempre
// como texto (interpolación); el IGV y el destino empiezan vacíos a propósito.
const props = defineProps<{
    pasajero: { reserva_pasajero_id: number; nombre: string | null };
    moneda: string;
    guardando: boolean;
    errorMensaje: string | null;
    // Al editar una penalidad ya cargada.
    inicial?: { monto: string; motivo: string; tip_afe_igv: string | null; destino_tributario: string | null } | null;
}>();

const emit = defineEmits<{
    (e: 'cerrar'): void;
    (e: 'guardar', payload: PenalidadPayload): void;
}>();

const monto = ref<string>(props.inicial?.monto ?? '');
const motivo = ref<string>(props.inicial?.motivo ?? '');
const tipAfeIgv = ref<string>(props.inicial?.tip_afe_igv ?? '');
const destino = ref<string>(props.inicial?.destino_tributario ?? '');

const esValido = computed(() =>
    monto.value !== '' && Number(monto.value) >= 0 && motivo.value.trim() !== '' && tipAfeIgv.value !== '' && destino.value !== ''
);

const guardar = () => {
    if (!esValido.value) return;
    emit('guardar', {
        monto: Number(monto.value),
        motivo: motivo.value.trim(),
        tip_afe_igv: tipAfeIgv.value,
        destino_tributario: destino.value,
    });
};
</script>
