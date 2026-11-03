<template>
    <!-- T-052 (CRM C3): «Cerrar como perdida». Motivo obligatorio; detalle obligatorio solo con «Otro».
         Las alternativas abiertas quedan descartadas; «Reabrir» las devuelve a su estado. -->
    <Teleport to="body">
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="fas fa-circle-xmark me-2 text-danger"></i>Cerrar como perdida — {{ codigo }}</h6>
                        <button type="button" class="btn-close" :disabled="guardando" @click="emit('cerrar')"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted mb-3">
                            Las alternativas en borrador o enviadas quedan descartadas. Si el cliente vuelve, «Reabrir» las deja como estaban.
                        </p>

                        <label class="form-label small mb-1">¿Por qué se perdió?</label>
                        <div v-if="cargando" class="small text-muted py-2"><span class="spinner-border spinner-border-sm me-1"></span>Cargando motivos…</div>
                        <div v-else class="d-flex flex-wrap gap-2 mb-3">
                            <button v-for="m in motivos" :key="m.id" type="button" class="btn btn-sm"
                                :class="motivoId === m.id ? 'btn-danger' : 'btn-outline-secondary'" :disabled="guardando" @click="motivoId = m.id">
                                {{ m.nombre }}
                            </button>
                        </div>

                        <label class="form-label small mb-1">
                            Detalle <span v-if="!exigeDetalle" class="text-muted">(opcional)</span>
                        </label>
                        <textarea v-model="detalle" rows="2" :maxlength="DETALLE_RESULTADO_MAX" class="form-control form-control-sm"
                            :class="{ 'is-invalid': errorDetalle }" :disabled="guardando"
                            :placeholder="exigeDetalle ? 'Cuenta brevemente por qué se perdió.' : 'Por ejemplo: le pareció caro el hotel.'"></textarea>
                        <div v-if="errorDetalle" class="invalid-feedback">{{ errorDetalle }}</div>

                        <div v-if="error" class="alert alert-danger small py-2 mt-3 mb-0">{{ error }}</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="guardando" @click="emit('cerrar')">Cancelar</button>
                        <button type="button" class="btn btn-danger btn-sm" :disabled="guardando || !puedeGuardar" @click="guardar">
                            <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-circle-xmark me-1"></i>Cerrar como perdida
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { cotizacionService } from '@/services/admin/cotizacionService';
import { crmMotivoResultadoService, DETALLE_RESULTADO_MAX, type MotivoResultado } from '@/services/admin/crmMotivoResultadoService';

const props = defineProps<{ cotizacionId: number; codigo: string }>();
const emit = defineEmits<{ cerrar: []; cerrada: [] }>();

const motivos = ref<MotivoResultado[]>([]);
const cargando = ref(true);
const motivoId = ref<number | null>(null);
const detalle = ref('');
const guardando = ref(false);
const error = ref('');
const errorDetalle = ref('');

const motivoElegido = computed(() => motivos.value.find((m) => m.id === motivoId.value) ?? null);
const exigeDetalle = computed(() => motivoElegido.value?.es_otro ?? false);
const puedeGuardar = computed(() => motivoId.value !== null && (!exigeDetalle.value || detalle.value.trim() !== ''));

onMounted(async () => {
    try {
        motivos.value = await crmMotivoResultadoService.listar({ tipo: 'perdida', solo_activos: true });
    } catch {
        error.value = 'No se pudieron cargar los motivos.';
    } finally {
        cargando.value = false;
    }
});

const guardar = async () => {
    if (!puedeGuardar.value || motivoId.value === null) return;
    guardando.value = true;
    error.value = '';
    errorDetalle.value = '';
    try {
        await cotizacionService.cerrarComoPerdida(props.cotizacionId, { motivo_id: motivoId.value, detalle: detalle.value.trim() || null });
        emit('cerrada');
    } catch (e: any) {
        const datos = e.response?.data;
        errorDetalle.value = datos?.errors?.detalle?.[0] ?? '';
        error.value = errorDetalle.value ? '' : (datos?.message ?? 'No se pudo cerrar la cotización.');
    } finally {
        guardando.value = false;
    }
};
</script>
