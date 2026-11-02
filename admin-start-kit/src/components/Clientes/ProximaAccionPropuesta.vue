<template>
    <!-- CRM C2 (T-051): la próxima acción que se propone al enviar una cotización ("Llamar el
         jue 05/11 (Ana)"). Se cambia o se quita en la misma línea, sin confirmar; no es obligatoria. -->
    <div class="small px-2 py-2 bg-light border-bottom">
        <template v-if="!editando">
            <div v-if="modelo" class="d-flex flex-wrap align-items-center gap-1">
                <i class="fas fa-phone-alt text-primary me-1"></i>
                <span>Después: llamar el <strong>{{ formatDiaCorto(modelo.fecha) }}</strong> ({{ nombreResponsable }})</span>
                <span class="ms-auto text-nowrap">
                    <a href="#" class="text-decoration-none me-2" @click.prevent="editando = true">Cambiar</a>
                    <a href="#" class="text-decoration-none text-muted" @click.prevent="modelo = null">Quitar</a>
                </span>
            </div>
            <div v-else class="d-flex align-items-center gap-1 text-muted">
                <span>Sin próxima acción.</span>
                <a href="#" class="text-decoration-none ms-auto" @click.prevent="proponer">Agendar una llamada</a>
            </div>
        </template>
        <div v-else-if="modelo" class="row g-1 align-items-end">
            <div class="col-6">
                <label class="form-label mb-0 text-muted">Llamar el</label>
                <CampoFecha v-model="modelo.fecha" :min="hoy" etiqueta="próxima llamada" :estatico="estatico" />
            </div>
            <div class="col-6">
                <label class="form-label mb-0 text-muted">Responsable</label>
                <select v-model.number="modelo.responsable_id" class="form-select form-select-sm">
                    <option v-for="r in opciones?.responsables ?? []" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                </select>
            </div>
            <div class="col-12 text-end">
                <button type="button" class="btn btn-link btn-sm p-0" :disabled="!modelo.fecha" @click="editando = false">Listo</button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import CampoFecha from '@/components/CampoFecha.vue';
import { useAuthStore } from '@/stores/auth';
import { formatDiaCorto, hoyPeru } from '@/helpers/fecha';
import { crmAccionService, fechaPropuesta, type OpcionesAcciones, type ProximaAccion } from '@/services/admin/crmAccionService';

withDefaults(defineProps<{
    /** Dentro de un modal: el calendario se dibuja junto al campo. */
    estatico?: boolean;
}>(), { estatico: false });

const modelo = defineModel<ProximaAccion>({ default: null });

const auth = useAuthStore();
const opciones = ref<OpcionesAcciones | null>(null);
const editando = ref(false);
const hoy = hoyPeru();

const nombreResponsable = computed(() => {
    const id = modelo.value?.responsable_id;
    if (id === auth.user?.id) return 'tú';
    return opciones.value?.responsables.find((r) => r.id === id)?.nombre ?? '…';
});

const proponer = () => {
    if (!auth.user?.id) return;
    modelo.value = { fecha: fechaPropuesta(opciones.value?.dias_proximo_contacto ?? 2), responsable_id: auth.user.id };
};

onMounted(async () => {
    try {
        opciones.value = await crmAccionService.opciones();
    } catch {
        opciones.value = null;
    }
});
</script>
