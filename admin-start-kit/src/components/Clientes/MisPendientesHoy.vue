<template>
    <!-- CRM C2 (T-051): "Mis pendientes de hoy" en Inicio (agencia): acciones con fecha hasta hoy,
         las atrasadas primero. Quien tiene cotizaciones.ver_todas puede ver también las de todos. -->
    <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 py-2">
            <h6 class="card-title mb-0">
                <i class="fas fa-tasks me-1 text-primary"></i>{{ ambito === 'todos' ? 'Pendientes de hoy (todos)' : 'Mis pendientes de hoy' }}
                <span v-if="atrasadas" class="badge bg-danger-subtle text-danger ms-1">{{ atrasadas }} atrasada(s)</span>
            </h6>
            <div class="d-flex align-items-center gap-2">
                <div v-if="puedeVerTodos" class="btn-group btn-group-sm" role="group" aria-label="De quién">
                    <button type="button" class="btn" :class="ambito === 'mios' ? 'btn-primary' : 'btn-outline-primary'" @click="cambiarAmbito('mios')">Míos</button>
                    <button type="button" class="btn" :class="ambito === 'todos' ? 'btn-primary' : 'btn-outline-primary'" @click="cambiarAmbito('todos')">De todos</button>
                </div>
                <router-link :to="{ name: 'agencia.seguimiento' }" class="small text-decoration-none">Ver seguimiento</router-link>
            </div>
        </div>
        <div class="card-body py-1">
            <div v-if="cargando" class="text-muted small py-2"><span class="spinner-border spinner-border-sm me-1"></span>Cargando…</div>
            <div v-else-if="!filas.length" class="text-muted fst-italic small py-2">Nada pendiente para hoy.</div>
            <ListaPendientes v-else :filas="filas" :mostrar-responsable="ambito === 'todos'" @cambio="recargar" />
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ListaPendientes from '@/components/Clientes/ListaPendientes.vue';
import { hoyPeru } from '@/helpers/fecha';
import { bandejaService, type FilaBandeja } from '@/services/admin/crmAccionService';

const props = defineProps<{
    /** Lo que trajo GET /dashboard (las propias): se muestra sin otra consulta. */
    inicial: { filas: FilaBandeja[]; puede_ver_todos: boolean };
}>();

const filas = ref<FilaBandeja[]>(props.inicial.filas);
const puedeVerTodos = computed(() => props.inicial.puede_ver_todos);
const ambito = ref<'mios' | 'todos'>('mios');
const cargando = ref(false);

const atrasadas = computed(() => filas.value.filter((f) => f.bloque === 'atrasadas').length);

watch(() => props.inicial, (valor) => {
    ambito.value = 'mios';
    filas.value = valor.filas;
});

const recargar = async () => {
    cargando.value = true;
    try {
        filas.value = (await bandejaService.pendientes({ ambito: ambito.value, hasta: hoyPeru() })).filas;
    } catch {
        filas.value = [];
    } finally {
        cargando.value = false;
    }
};

const cambiarAmbito = (valor: 'mios' | 'todos') => {
    if (ambito.value === valor) return;
    ambito.value = valor;
    recargar();
};
</script>
