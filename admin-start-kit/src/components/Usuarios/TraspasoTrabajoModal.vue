<template>
    <!-- T-061 (CRM): «Pasar su trabajo a…». Pasa las cotizaciones abiertas y las acciones pendientes
         del usuario a otro; las cerradas y las reservas no cambian. Solo Agencia. -->
    <Teleport to="body">
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="fas fa-people-arrows me-2 text-primary"></i>Pasar su trabajo — {{ resumen?.usuario.nombre ?? nombre ?? "" }}</h6>
                        <button type="button" class="btn-close" :disabled="guardando" @click="emit('cerrar')"></button>
                    </div>
                    <div class="modal-body">
                        <div v-if="cargando" class="small text-muted py-2"><span class="spinner-border spinner-border-sm me-1"></span>Cargando…</div>
                        <template v-else-if="resumen">
                            <p v-if="tieneTrabajo(resumen.data)" class="small mb-3">
                                Tiene {{ textoTrabajo(resumen.data) }}. Pasarán a quien elijas; las cotizaciones
                                ganadas o perdidas y las reservas siguen a su nombre.
                            </p>
                            <p v-else class="small text-muted mb-3">No tiene trabajo abierto para pasar.</p>

                            <template v-if="tieneTrabajo(resumen.data)">
                                <label for="traspaso-destino" class="form-label small mb-1">Pasar a</label>
                                <select id="traspaso-destino" v-model="haciaId" class="form-select form-select-sm" :disabled="guardando">
                                    <option :value="null">Elige un usuario…</option>
                                    <option v-for="d in resumen.destinos" :key="d.id" :value="d.id">{{ d.nombre }}</option>
                                </select>
                                <div v-if="!resumen.destinos.length" class="form-text">No hay usuarios activos con permiso para editar cotizaciones.</div>
                            </template>
                        </template>

                        <div v-if="error" class="alert alert-danger small py-2 mt-3 mb-0">{{ error }}</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="guardando" @click="emit('cerrar')">Cancelar</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="guardando || haciaId === null" @click="guardar">
                            <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-people-arrows me-1"></i>Pasar su trabajo
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useToast } from '@/composables/useToast';
import {
    textoTrabajo, tieneTrabajo, traspasoTrabajoService, type ResultadoTraspaso, type ResumenTraspaso,
} from '@/services/admin/traspasoTrabajoService';

// nombre: el que ya se conoce (fila de Usuarios); desde Inicio llega solo el id y lo trae el resumen.
const props = defineProps<{ usuarioId: number; nombre?: string }>();
const emit = defineEmits<{ cerrar: []; hecho: [resultado: ResultadoTraspaso] }>();

const toast = useToast();
const resumen = ref<ResumenTraspaso | null>(null);
const cargando = ref(true);
const haciaId = ref<number | null>(null);
const guardando = ref(false);
const error = ref('');

onMounted(async () => {
    try {
        resumen.value = await traspasoTrabajoService.resumen(props.usuarioId);
    } catch (e: any) {
        error.value = e.response?.data?.message ?? 'No se pudo cargar su trabajo abierto.';
    } finally {
        cargando.value = false;
    }
});

const guardar = async () => {
    if (haciaId.value === null) return;
    guardando.value = true;
    error.value = '';
    try {
        const resultado = await traspasoTrabajoService.traspasar(props.usuarioId, haciaId.value);
        const destino = resumen.value?.destinos.find((d) => d.id === haciaId.value)?.nombre ?? 'el usuario elegido';
        toast.success(`Trabajo pasado a ${destino}: ${textoTrabajo(resultado)}.`);
        emit('hecho', resultado);
    } catch (e: any) {
        error.value = e.response?.data?.message ?? 'No se pudo pasar su trabajo.';
    } finally {
        guardando.value = false;
    }
};
</script>
