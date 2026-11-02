<template>
    <!-- CRM C1 (T-050): anotar una llamada, WhatsApp, visita o nota con el cliente. Se usa en la
         ficha del cliente y en el cotizador (con la cotización como referencia). -->
    <button type="button" :class="claseBoton" @click="abrir">
        <i class="fas fa-comment-dots me-1"></i>Anotar contacto
    </button>

    <Teleport to="body">
        <div v-if="modalAbierto" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="fas fa-comment-dots me-2"></i>Anotar contacto con el cliente</h6>
                        <button type="button" class="btn-close" :disabled="guardando" @click="cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label small mb-1">Tipo de contacto</label>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <template v-for="t in TIPOS_CONTACTO" :key="t.valor">
                                <input :id="`contacto-${t.valor}`" v-model="form.tipo" type="radio" class="btn-check" :value="t.valor" autocomplete="off">
                                <label class="btn btn-outline-primary btn-sm" :for="`contacto-${t.valor}`">{{ t.etiqueta }}</label>
                            </template>
                        </div>
                        <label class="form-label small mb-1">
                            Nota <span v-if="form.tipo === 'nota'" class="text-danger">*</span><span v-else class="text-muted">(opcional)</span>
                        </label>
                        <textarea v-model="form.nota" rows="4" maxlength="1000" class="form-control form-control-sm"
                            placeholder="Qué conversaron, qué pidió el cliente…"></textarea>
                        <small class="text-muted">{{ form.nota.length }}/1000</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="guardando" @click="cerrar">Cancelar</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="guardando || !puedeGuardar" @click="guardar">
                            <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-save me-1"></i>Guardar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useToast } from '@/composables/useToast';
import { crmActividadService, TIPOS_CONTACTO, type TipoContactoManual } from '@/services/admin/crmActividadService';

const props = withDefaults(defineProps<{
    clienteId: number;
    /** Desde el cotizador: la actividad queda referida a esta cotización. */
    cotizacionId?: number | null;
    claseBoton?: string;
}>(), {
    cotizacionId: null,
    claseBoton: 'btn btn-outline-secondary btn-sm',
});

const emit = defineEmits<{ (e: 'anotado'): void }>();

const toast = useToast();

const modalAbierto = ref(false);
const guardando = ref(false);
const form = reactive<{ tipo: TipoContactoManual; nota: string }>({ tipo: 'llamada', nota: '' });

const puedeGuardar = computed(() => form.tipo !== 'nota' || form.nota.trim().length > 0);

const abrir = () => {
    form.tipo = 'llamada';
    form.nota = '';
    modalAbierto.value = true;
};

const cerrar = () => {
    if (!guardando.value) modalAbierto.value = false;
};

const guardar = async () => {
    guardando.value = true;
    try {
        const resultado = await crmActividadService.anotar(props.clienteId, {
            tipo: form.tipo,
            nota: form.nota.trim() || null,
            ...(props.cotizacionId ? { referencia_tipo: 'cotizacion' as const, referencia_id: props.cotizacionId } : {}),
        });
        modalAbierto.value = false;
        toast.success(resultado.message);
        emit('anotado');
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? 'No se pudo anotar el contacto.');
    } finally {
        guardando.value = false;
    }
};
</script>
