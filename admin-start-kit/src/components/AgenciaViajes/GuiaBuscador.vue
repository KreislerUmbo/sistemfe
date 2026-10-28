<template>
    <div class="position-relative">
        <input ref="inputRef" type="text" class="form-control form-control-sm" :placeholder="placeholder" :disabled="disabled"
            v-model="texto" @input="buscarConPausa" @focus="alEnfocar" @blur="cerrarLuego">
        <ul v-if="abierto" class="list-group shadow-sm" :style="estiloLista">
            <li v-if="buscando" class="list-group-item small text-muted py-1">
                <span class="spinner-border spinner-border-sm me-1"></span>Buscando…
            </li>
            <li v-else-if="resultados.length === 0" class="list-group-item small text-muted py-1">Sin coincidencias</li>
            <button v-for="g in resultados" :key="g.id" type="button" class="list-group-item list-group-item-action small py-1"
                @mousedown.prevent="elegir(g)">
                {{ nombreDe(g) }}
            </button>
            <button v-if="modelValue" type="button" class="list-group-item list-group-item-action small py-1 text-danger"
                @mousedown.prevent="elegir(null)">
                {{ textoQuitar }}
            </button>
        </ul>
    </div>
</template>

<script setup lang="ts">
// T-039 — buscador de guía contra el servidor (guiaService.listar con
// ?search=), mismo comportamiento que ProveedorBuscador: al abrirlo muestra
// los primeros y al escribir busca entre todos, sin depender de cuántos guías
// tenga la agencia (el listado pagina de a 15).
import { ref, watch } from 'vue';
import { useListaFlotante } from '@/composables/agencia-viajes/useListaFlotante';
import { guiaService } from '@/services/admin/guiaService';
import type { Guia } from '@/types/agencia-viajes';

const props = withDefaults(defineProps<{
    modelValue: number | null;
    // Nombre del guía ya elegido (para mostrarlo sin buscarlo).
    nombreActual?: string | null;
    placeholder?: string;
    textoQuitar?: string;
    disabled?: boolean;
}>(), { nombreActual: null, placeholder: 'Buscar guía…', textoQuitar: 'Quitar guía', disabled: false });

const emit = defineEmits<{
    (e: 'update:modelValue', id: number | null): void;
    (e: 'elegido', guia: Guia | null): void;
}>();

const nombreDe = (g: Guia) => g.nombre + (g.es_referencial ? ' (Referencial)' : '');

// Nombre del guía elegido: lo que se vuelve a mostrar al salir sin elegir.
const nombreElegido = ref(props.nombreActual ?? '');
const idDelNombre = ref<number | null>(props.nombreActual ? props.modelValue : null);
const texto = ref(nombreElegido.value);
const resultados = ref<Guia[]>([]);
const abierto = ref(false);
const inputRef = ref<HTMLInputElement | null>(null);
const { estilo: estiloLista } = useListaFlotante(inputRef, abierto);
const buscando = ref(false);
let pausa: ReturnType<typeof setTimeout> | null = null;

const mostrar = (id: number | null, nombre: string) => {
    idDelNombre.value = id;
    nombreElegido.value = nombre;
    texto.value = nombre;
};

watch(() => props.nombreActual, (nombre) => mostrar(props.modelValue, nombre ?? ''));
watch(() => props.modelValue, async (id) => {
    if (!id) {
        if (!props.nombreActual) mostrar(null, '');
        return;
    }
    if (props.nombreActual || id === idDelNombre.value) return;
    try {
        const res = await guiaService.obtener(id);
        mostrar(id, nombreDe(res.guia));
    } catch {
        // Se puede seguir eligiendo otro guía aunque el registro ya no exista.
    }
}, { immediate: true });

const buscar = async () => {
    buscando.value = true;
    abierto.value = true;
    try {
        const consulta = texto.value === nombreElegido.value ? '' : texto.value.trim();
        const res = await guiaService.listar({ search: consulta || undefined, per_page: 15 });
        resultados.value = res.guias ?? [];
    } finally {
        buscando.value = false;
    }
};

// Al entrar, el texto del elegido queda seleccionado (escribir lo reemplaza) y
// la lista muestra los primeros, no solo el elegido.
const alEnfocar = (e: FocusEvent) => {
    (e.target as HTMLInputElement).select();
    abierto.value = true;
    if (!resultados.value.length) buscar();
};

const buscarConPausa = () => {
    if (pausa) clearTimeout(pausa);
    pausa = setTimeout(buscar, 300);
};

const elegir = (g: Guia | null) => {
    mostrar(g?.id ?? null, g ? nombreDe(g) : '');
    abierto.value = false;
    emit('update:modelValue', g?.id ?? null);
    emit('elegido', g);
};

// Al salir sin elegir, vuelve a mostrar el guía que ya estaba.
const cerrarLuego = () => {
    setTimeout(() => {
        abierto.value = false;
        texto.value = nombreElegido.value;
    }, 150);
};
</script>
