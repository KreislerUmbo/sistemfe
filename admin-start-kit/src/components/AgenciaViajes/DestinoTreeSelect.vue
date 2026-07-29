<template>
    <div class="position-relative">
        <div class="input-group input-group-sm">
            <input type="text" class="form-control" :placeholder="placeholder" v-model="searchText"
                @focus="showSuggestions = true" @blur="onBlur" autocomplete="off" />
            <button v-if="modelValue" class="btn btn-outline-danger" type="button" @click="limpiar"
                title="Quitar selección">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div v-if="showSuggestions && opcionesFiltradas.length > 0" class="list-group mt-1 position-absolute"
            style="max-height:260px;overflow-y:auto;z-index:1050;width:100%;box-shadow:0 4px 8px rgba(0,0,0,.1)">
            <button type="button" class="list-group-item list-group-item-action" v-for="opcion in opcionesFiltradas"
                :key="opcion.id" @mousedown.prevent="seleccionar(opcion)">
                <span :style="{ paddingLeft: (opcion.profundidad * 14) + 'px' }">
                    <i class="fas me-1" :class="iconoPorTipo(opcion.tipo)"></i>
                    {{ opcion.nombre }}
                    <span class="badge bg-light text-muted ms-1">{{ etiquetaTipo(opcion.tipo) }}</span>
                </span>
            </button>
        </div>
        <div v-if="showSuggestions && searchText.length > 0 && opcionesFiltradas.length === 0"
            class="text-muted small mt-1">
            Sin resultados.
        </div>
    </div>
</template>

<script setup lang="ts">
// Selector reutilizable de destinos_atractivos (árbol zona/lugar/atractivo),
// con búsqueda — Sesión 11a. Pensado para reusarse en el cotizador (Sesión
// 11b, ver plan-modulo-cotizaciones-reservas.md §7.1) además de en los
// formularios de maestros de esta sesión, por eso acepta nivelMin/nivelMax
// en vez de asumir que siempre se puede elegir cualquier nivel del árbol.
import { ref, computed, onMounted, watch } from 'vue';
import { destinoAtractivoService } from '@/services/admin/destinoAtractivoService';
import type { DestinoAtractivo } from '@/types/agencia-viajes';

type Nivel = 'zona' | 'lugar' | 'atractivo';

const NIVEL_ORDEN: Record<Nivel, number> = { zona: 0, lugar: 1, atractivo: 2 };

const props = withDefaults(defineProps<{
    modelValue: number | null;
    nivelMin?: Nivel;
    nivelMax?: Nivel;
    placeholder?: string;
}>(), {
    nivelMin: 'zona',
    nivelMax: 'atractivo',
    placeholder: 'Buscar destino/atractivo...',
});

// update:label — Sesión 11b: cotizaciones.destino es texto libre (no FK),
// así que el "Paso 0" del cotizador necesita el NOMBRE elegido, no solo el
// id. Opcional para el resto de callers (guias/detalle.vue,
// proveedores/detalle.vue) que solo usan el id — no rompe nada existente.
const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
    (e: 'update:label', value: string): void;
}>();

type OpcionPlana = { id: number; nombre: string; tipo: Nivel; profundidad: number; etiquetaCompleta: string };

const arbol = ref<DestinoAtractivo[]>([]);
const opcionesPlanas = ref<OpcionPlana[]>([]);
const searchText = ref<string>('');
const showSuggestions = ref<boolean>(false);

const aplanar = (nodos: DestinoAtractivo[], profundidad = 0, prefijo = ''): OpcionPlana[] => {
    let resultado: OpcionPlana[] = [];
    for (const nodo of nodos) {
        const etiquetaCompleta = prefijo ? `${prefijo} > ${nodo.nombre}` : nodo.nombre;
        resultado.push({ id: nodo.id, nombre: nodo.nombre, tipo: nodo.tipo, profundidad, etiquetaCompleta });
        if (nodo.hijos && nodo.hijos.length > 0) {
            resultado = resultado.concat(aplanar(nodo.hijos, profundidad + 1, etiquetaCompleta));
        }
    }
    return resultado;
};

const cargarArbol = async () => {
    try {
        const res = await destinoAtractivoService.arbol();
        arbol.value = res.destinos_atractivos;
        opcionesPlanas.value = aplanar(arbol.value).filter(
            (o) => NIVEL_ORDEN[o.tipo] >= NIVEL_ORDEN[props.nivelMin] && NIVEL_ORDEN[o.tipo] <= NIVEL_ORDEN[props.nivelMax]
        );
    } catch (error) {
        console.log(error);
    }
};

const opcionesFiltradas = computed(() => {
    if (searchText.value.length === 0) {
        return opcionesPlanas.value.slice(0, 30);
    }
    const q = searchText.value.toLowerCase();
    return opcionesPlanas.value.filter((o) => o.etiquetaCompleta.toLowerCase().includes(q)).slice(0, 30);
});

const seleccionar = (opcion: OpcionPlana) => {
    emit('update:modelValue', opcion.id);
    emit('update:label', opcion.nombre);
    searchText.value = opcion.etiquetaCompleta;
    showSuggestions.value = false;
};

const limpiar = () => {
    emit('update:modelValue', null);
    searchText.value = '';
};

const onBlur = () => {
    setTimeout(() => { showSuggestions.value = false; }, 150);
};

const iconoPorTipo = (tipo: Nivel) => {
    if (tipo === 'zona') return 'fa-globe-americas';
    if (tipo === 'lugar') return 'fa-map-marker-alt';
    return 'fa-mountain';
};

const etiquetaTipo = (tipo: Nivel) => {
    if (tipo === 'zona') return 'Zona';
    if (tipo === 'lugar') return 'Lugar';
    return 'Atractivo';
};

watch(() => props.modelValue, (value) => {
    if (!value) {
        searchText.value = '';
        return;
    }
    const opcion = opcionesPlanas.value.find((o) => o.id === value);
    if (opcion) {
        searchText.value = opcion.etiquetaCompleta;
    }
});

onMounted(() => {
    cargarArbol();
});
</script>
