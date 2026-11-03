<template>
    <!-- T-053 (CRM C6a): «Extender vigencia» de una alternativa enviada, vigente o vencida. La
         alternativa sigue enviada; queda vigente todo el día elegido y el cambio va al historial. -->
    <Teleport to="body">
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="fas fa-calendar-plus me-2 text-primary"></i>Extender vigencia — {{ nombre }}</h6>
                        <button type="button" class="btn-close" :disabled="guardando" @click="emit('cerrar')"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label small mb-1">Vigente hasta el</label>
                        <CampoFecha v-model="fecha" :min="hoy" etiqueta="la nueva vigencia" estatico />
                        <div v-if="error" class="alert alert-danger small py-2 mt-3 mb-0">{{ error }}</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="guardando" @click="emit('cerrar')">Cancelar</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="guardando || !fecha" @click="guardar">
                            <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-check me-1"></i>Guardar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import CampoFecha from '@/components/CampoFecha.vue';
import { crmActividadService } from '@/services/admin/crmActividadService';
import { hoyPeru, sumarDiasISO } from '@/helpers/fecha';

// diasVigencia: los de Configuración de Agencia; sin configurar se proponen 7.
const props = defineProps<{ alternativaId: number; nombre: string; diasVigencia?: number | null }>();
const emit = defineEmits<{ cerrar: []; extendida: [fechaVencimiento: string, mensaje: string] }>();

const hoy = hoyPeru();
const fecha = ref(sumarDiasISO(hoy, props.diasVigencia || 7));
const guardando = ref(false);
const error = ref('');

const guardar = async () => {
    if (!fecha.value) return;
    guardando.value = true;
    error.value = '';
    try {
        const res = await crmActividadService.extenderVigencia(props.alternativaId, fecha.value);
        emit('extendida', res.fecha_vencimiento, res.message);
    } catch (e: any) {
        error.value = e.response?.data?.message ?? 'No se pudo extender la vigencia.';
    } finally {
        guardando.value = false;
    }
};
</script>
