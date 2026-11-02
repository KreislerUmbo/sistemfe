<template>
    <DefaultLayout>
        <!-- CRM C2 (T-051): bandeja de pendientes en tres bloques (atrasadas, hoy, próximos 7 días).
             Es la bandeja única de la fila 11f: hoy solo trae acciones del CRM; cuando sume
             pendientes de operación aparecen las pestañas por origen. -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-tasks me-2 text-primary"></i>Seguimiento
                </h5>
                <small class="text-muted">{{ filasVisibles.length }} pendiente(s) hasta el {{ formatDiaCorto(hasta) }}</small>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-2">
                    <div v-if="puedeVerTodos" class="col-12 col-md-4">
                        <select v-model="responsable" class="form-select form-select-sm" @change="cargar">
                            <option value="mios">Mis pendientes</option>
                            <option value="todos">De todos</option>
                            <option v-for="r in responsables" :key="r.id" :value="String(r.id)">{{ r.nombre }}</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <select v-model="tipo" class="form-select form-select-sm" @change="cargar">
                            <option value="">Todas las acciones</option>
                            <option v-for="t in tipos" :key="t.valor" :value="t.valor">{{ t.etiqueta }}</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <ul v-if="fuentes.length > 1" class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a href="#" class="nav-link" :class="{ active: fuente === '' }" @click.prevent="fuente = ''">Todos</a>
            </li>
            <li v-for="f in fuentes" :key="f" class="nav-item">
                <a href="#" class="nav-link" :class="{ active: fuente === f }" @click.prevent="fuente = f">{{ ETIQUETA_FUENTE[f] }}</a>
            </li>
        </ul>

        <div v-if="cargando" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
        <template v-else>
            <div v-for="b in BLOQUES" :key="b.valor" class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="fw-semibold mb-0" :class="b.clase">
                        <i class="fas me-1" :class="b.icono"></i>{{ b.titulo }}
                        <span class="badge bg-light text-dark ms-1">{{ porBloque(b.valor).length }}</span>
                    </h6>
                </div>
                <div class="card-body py-1">
                    <div v-if="!porBloque(b.valor).length" class="text-muted fst-italic small py-2">{{ b.vacio }}</div>
                    <ListaPendientes v-else :filas="porBloque(b.valor)" :mostrar-responsable="responsable !== 'mios'" @cambio="cargar" />
                </div>
            </div>
        </template>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import ListaPendientes from '@/components/Clientes/ListaPendientes.vue';
import { useToast } from '@/composables/useToast';
import { formatDiaCorto, hoyPeru, sumarDiasISO } from '@/helpers/fecha';
import {
    bandejaService, crmAccionService,
    type BloquePendiente, type FilaBandeja, type FiltrosBandeja, type FuentePendiente, type OpcionesAcciones, type TipoAccion,
} from '@/services/admin/crmAccionService';

const BLOQUES: { valor: BloquePendiente; titulo: string; icono: string; clase: string; vacio: string }[] = [
    { valor: 'atrasadas', titulo: 'Atrasadas', icono: 'fa-exclamation-circle', clase: 'text-danger', vacio: 'Nada atrasado.' },
    { valor: 'hoy', titulo: 'Hoy', icono: 'fa-calendar-day', clase: 'text-warning', vacio: 'Nada para hoy.' },
    { valor: 'proximos', titulo: 'Próximos 7 días', icono: 'fa-calendar-week', clase: 'text-primary', vacio: 'Nada en los próximos días.' },
];
const ETIQUETA_FUENTE: Record<FuentePendiente, string> = { crm: 'Mis acciones', operacion: 'Operación' };

const toast = useToast();

const filas = ref<FilaBandeja[]>([]);
const hasta = ref(sumarDiasISO(hoyPeru(), 7));
const puedeVerTodos = ref(false);
const cargando = ref(true);
const opciones = ref<OpcionesAcciones | null>(null);

// 'mios' | 'todos' | id de un responsable (solo con cotizaciones.ver_todas).
const responsable = ref('mios');
const tipo = ref<TipoAccion | ''>('');
const fuente = ref<FuentePendiente | ''>('');

const tipos = computed(() => opciones.value?.tipos ?? []);
const responsables = computed(() => opciones.value?.responsables ?? []);
const fuentes = computed(() => [...new Set(filas.value.map((f) => f.fuente))]);
const filasVisibles = computed(() => (fuente.value ? filas.value.filter((f) => f.fuente === fuente.value) : filas.value));
const porBloque = (bloque: BloquePendiente) => filasVisibles.value.filter((f) => f.bloque === bloque);

const filtros = (): FiltrosBandeja => {
    const base: FiltrosBandeja = { tipo: tipo.value || null };
    if (responsable.value === 'mios') return { ...base, ambito: 'mios' };
    if (responsable.value === 'todos') return { ...base, ambito: 'todos' };
    return { ...base, ambito: 'todos', responsable_id: Number(responsable.value) };
};

const cargar = async () => {
    cargando.value = true;
    try {
        const respuesta = await bandejaService.pendientes(filtros());
        filas.value = respuesta.filas;
        hasta.value = respuesta.hasta;
        puedeVerTodos.value = respuesta.puede_ver_todos;
    } catch (error: any) {
        filas.value = [];
        toast.error(error?.response?.data?.message ?? 'No se pudo cargar el seguimiento.');
    } finally {
        cargando.value = false;
    }
};

onMounted(async () => {
    const [, opcionesCargadas] = await Promise.all([cargar(), crmAccionService.opciones().catch(() => null)]);
    opciones.value = opcionesCargadas;
});
</script>
