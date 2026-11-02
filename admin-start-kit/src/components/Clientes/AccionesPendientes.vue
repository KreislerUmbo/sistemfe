<template>
    <!-- CRM C2 (T-051): las próximas acciones pendientes de una cotización, una reserva o de todo el
         cliente (ficha), la más próxima primero, con "Agendar acción", "Hecha" y "Reprogramar". -->
    <div class="small">
        <div class="d-flex justify-content-between align-items-center gap-2">
            <span class="text-muted"><i class="fas fa-tasks me-1"></i>{{ titulo }} ({{ acciones.length }})</span>
            <button v-if="puedeAgendar" type="button" class="btn btn-link btn-sm p-0 text-decoration-none" @click="agendar">
                <i class="fas fa-calendar-plus me-1"></i>Agendar acción
            </button>
        </div>
        <div v-if="cargando" class="text-muted mt-1"><span class="spinner-border spinner-border-sm me-1"></span>Cargando…</div>
        <div v-else-if="acciones.length === 0" class="text-muted mt-1">Sin acciones pendientes.</div>
        <ul v-else class="list-unstyled mt-2 mb-0">
            <li v-for="a in acciones" :key="a.id" class="border-start border-2 ps-2 mb-2" :class="claseBorde(a)">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <span class="fw-semibold">{{ a.tipo_etiqueta }}</span>
                        <span class="text-muted"> · {{ formatDiaCorto(a.fecha) }} · {{ a.responsable.nombre }}</span>
                        <span v-if="a.fecha < hoy" class="badge bg-danger-subtle text-danger ms-1">Atrasada</span>
                        <span v-else-if="a.fecha === hoy" class="badge bg-warning-subtle text-warning ms-1">Hoy</span>
                        <div v-if="a.descripcion" class="text-break">{{ a.descripcion }}</div>
                    </div>
                    <span v-if="a.puede_gestionar" class="text-nowrap">
                        <button type="button" class="btn btn-link btn-sm p-0 me-2 text-decoration-none" @click="modalRef?.marcarHecha(a)">Hecha</button>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-muted" @click="modalRef?.reprogramar(a)">Reprogramar</button>
                    </span>
                </div>
            </li>
        </ul>
        <AccionCrmModal ref="modalRef" @guardado="alGuardar" />
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AccionCrmModal from '@/components/Clientes/AccionCrmModal.vue';
import { useAuthStore } from '@/stores/auth';
import { formatDiaCorto, hoyPeru } from '@/helpers/fecha';
import { crmAccionService, type AccionPendiente, type ContextoAcciones, type ReferenciaCrm } from '@/services/admin/crmAccionService';

const props = withDefaults(defineProps<{
    clienteId: number;
    /** Sin referencia: todas las pendientes del cliente (ficha). */
    referenciaTipo?: ReferenciaCrm | null;
    referenciaId?: number | null;
    titulo?: string;
}>(), { referenciaTipo: null, referenciaId: null, titulo: 'Próximas acciones' });

const emit = defineEmits<{ (e: 'cambio'): void }>();

const auth = useAuthStore();
const hoy = hoyPeru();
const modalRef = ref<InstanceType<typeof AccionCrmModal> | null>(null);
const acciones = ref<AccionPendiente[]>([]);
const cargando = ref(false);

// Mismo permiso que pide el backend para agendar desde cada pantalla (la Policy de la fila la
// revisa el backend).
const puedeAgendar = computed(() => {
    if (props.referenciaTipo === 'cotizacion') return auth.isPermitedRoute('cotizaciones.editar');
    if (props.referenciaTipo === 'reserva') return auth.isPermitedRoute('reservas.editar');
    return auth.isPermitedRoute('edit_client');
});

const contexto = (): ContextoAcciones =>
    props.referenciaTipo && props.referenciaId
        ? { referencia_tipo: props.referenciaTipo, referencia_id: props.referenciaId }
        : { cliente_id: props.clienteId };

const claseBorde = (a: AccionPendiente) => (a.fecha < hoy ? 'border-danger' : a.fecha === hoy ? 'border-warning' : 'border-primary');

const recargar = async () => {
    cargando.value = acciones.value.length === 0;
    try {
        acciones.value = await crmAccionService.pendientes(contexto());
    } catch {
        acciones.value = [];
    } finally {
        cargando.value = false;
    }
};

const agendar = () => modalRef.value?.agendar(props.clienteId, props.referenciaTipo, props.referenciaId);

const alGuardar = async () => {
    await recargar();
    emit('cambio');
};

watch(() => [props.clienteId, props.referenciaTipo, props.referenciaId], recargar, { immediate: true });

/** La más próxima: para la cabecera "Próxima acción" del cotizador. */
const proxima = computed<AccionPendiente | null>(() => acciones.value[0] ?? null);

defineExpose({ recargar, proxima, cantidad: computed(() => acciones.value.length) });
</script>
