<template>
    <!-- T-052 (CRM C3): confirmación de «Aceptado por cliente» (antes un Swal) con la pregunta
         opcional «¿Qué decidió la venta?». El padre hace la llamada; acá solo se elige. -->
    <Teleport to="body">
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="fas fa-handshake me-2 text-success"></i>¿Aceptar esta alternativa? — {{ alternativaNombre }}</h6>
                        <button type="button" class="btn-close" :disabled="procesando" @click="emit('cerrar')"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning small py-2">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Se genera una reserva y se descartan las demás alternativas de esta cotización — no se puede deshacer.
                        </div>

                        <template v-if="motivos.length">
                            <label class="form-label small mb-1">¿Qué decidió la venta? <span class="text-muted">(opcional)</span></label>
                            <div class="d-flex flex-wrap gap-2">
                                <button v-for="m in motivos" :key="m.id" type="button" class="btn btn-sm"
                                    :class="motivoId === m.id ? 'btn-success' : 'btn-outline-secondary'" :disabled="procesando"
                                    @click="motivoId = motivoId === m.id ? null : m.id">
                                    {{ m.nombre }}
                                </button>
                            </div>
                            <input v-if="motivoElegido?.es_otro" v-model="detalle" type="text" :maxlength="DETALLE_RESULTADO_MAX"
                                class="form-control form-control-sm mt-2" placeholder="¿Qué fue? (opcional)" :disabled="procesando">
                        </template>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="procesando" @click="emit('cerrar')">Cancelar</button>
                        <button type="button" class="btn btn-success btn-sm" :disabled="procesando" @click="confirmar">
                            <span v-if="procesando" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-check me-1"></i>Sí, aceptar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { crmMotivoResultadoService, DETALLE_RESULTADO_MAX, type MotivoResultado } from '@/services/admin/crmMotivoResultadoService';

defineProps<{ alternativaNombre: string; procesando: boolean }>();
const emit = defineEmits<{
    cerrar: [];
    confirmar: [resultado: { resultado_motivo_id: number | null; resultado_detalle: string | null }];
}>();

const motivos = ref<MotivoResultado[]>([]);
const motivoId = ref<number | null>(null);
const detalle = ref('');

const motivoElegido = computed(() => motivos.value.find((m) => m.id === motivoId.value) ?? null);

// Si no cargan, se acepta igual sin preguntar: el motivo es opcional.
onMounted(async () => {
    try {
        motivos.value = await crmMotivoResultadoService.listar({ tipo: 'ganada', solo_activos: true });
    } catch {
        motivos.value = [];
    }
});

const confirmar = () => {
    emit('confirmar', {
        resultado_motivo_id: motivoId.value,
        resultado_detalle: motivoElegido.value?.es_otro ? (detalle.value.trim() || null) : null,
    });
};
</script>
