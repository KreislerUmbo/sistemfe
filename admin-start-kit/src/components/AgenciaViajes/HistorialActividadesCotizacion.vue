<template>
    <!-- CRM C1 (T-050): contactos con el cliente sobre esta cotización (envíos, llamadas, notas…)
         con usuario y hora de Perú. Reemplaza la lista "Envíos por correo": los correos que no
         salieron siguen apareciendo, en rojo. -->
    <div v-if="puedeVer" class="small">
        <div class="d-flex justify-content-between align-items-center gap-2">
            <a href="#" class="text-muted text-decoration-none" @click.prevent="abierto = !abierto">
                <i class="fas me-1" :class="abierto ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                Historial con el cliente ({{ actividades.length }})
            </a>
            <AnotarContacto v-if="puedeAnotar && clienteId" :cliente-id="clienteId" :cotizacion-id="cotizacionId"
                clase-boton="btn btn-link btn-sm p-0 text-decoration-none" @anotado="recargar" />
        </div>
        <template v-if="abierto">
            <div v-if="actividades.length === 0" class="text-muted mt-2">Todavía no hay contactos registrados.</div>
            <ul v-else class="list-unstyled mt-2 mb-0">
                <li v-for="a in actividades" :key="a.id" class="border-start border-2 ps-2 mb-2"
                    :class="a.fallido ? 'border-danger' : 'border-primary'">
                    <div class="d-flex justify-content-between gap-2">
                        <span class="fw-semibold"><i class="fas me-1" :class="icono(a)"></i>{{ titulo(a) }}</span>
                        <span v-if="a.fallido" class="badge bg-danger-subtle text-danger">Falló</span>
                    </div>
                    <div class="text-muted">{{ formatFechaHora(a.fecha) }}<template v-if="a.usuario"> · {{ a.usuario }}</template></div>
                    <div v-if="a.alternativa" class="text-muted">Alternativa: {{ a.alternativa }}</div>
                    <div v-if="a.nota" class="text-break" style="white-space: pre-line">{{ a.nota }}</div>
                    <div v-if="a.error" class="text-danger text-break">{{ a.error }}</div>
                </li>
            </ul>
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AnotarContacto from '@/components/Clientes/AnotarContacto.vue';
import { useAuthStore } from '@/stores/auth';
import { formatFechaHora } from '@/helpers/fecha';
import { crmActividadService, type ActividadHistorial } from '@/services/admin/crmActividadService';

const props = defineProps<{
    cotizacionId: number;
    clienteId: number | null;
}>();

const auth = useAuthStore();
const puedeVer = computed(() => auth.isPermitedRoute('cotizaciones.ver|cotizaciones.ver_todas'));
const puedeAnotar = computed(() => auth.isPermitedRoute('cotizaciones.editar'));

const actividades = ref<ActividadHistorial[]>([]);
const abierto = ref(false);

const CANALES: Record<string, string> = { correo: 'por correo', whatsapp: 'por WhatsApp', otro: 'por otro medio' };
const TITULOS: Record<string, string> = { llamada: 'Llamada', whatsapp: 'WhatsApp', visita: 'Visita', nota: 'Nota', estado: 'Cambio de estado' };
const ICONOS: Record<string, string> = {
    llamada: 'fa-phone', whatsapp: 'fa-comment', visita: 'fa-handshake', nota: 'fa-sticky-note', estado: 'fa-exchange-alt',
};

const titulo = (a: ActividadHistorial) =>
    a.tipo === 'envio' ? `Enviada ${CANALES[a.canal ?? 'otro'] ?? ''}`.trim() : (TITULOS[a.tipo] ?? a.tipo);

const icono = (a: ActividadHistorial) => {
    if (a.tipo !== 'envio') return ICONOS[a.tipo] ?? 'fa-circle';
    return a.canal === 'correo' ? 'fa-envelope' : a.canal === 'whatsapp' ? 'fa-comment' : 'fa-paper-plane';
};

const cargar = async () => {
    if (!puedeVer.value || !props.cotizacionId) return;
    try {
        actividades.value = await crmActividadService.historialCotizacion(props.cotizacionId);
    } catch {
        actividades.value = [];
    }
};

/** Tras un envío o un contacto anotado: se vuelve a pedir y se deja abierto para que se vea. */
const recargar = async () => {
    await cargar();
    abierto.value = true;
};

watch(() => props.cotizacionId, () => {
    abierto.value = false;
    cargar();
}, { immediate: true });

defineExpose({ recargar });
</script>
