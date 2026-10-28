<template>
    <div class="position-relative">
        <input ref="inputRef" type="text" class="form-control form-control-sm" :placeholder="placeholder"
            v-model="texto" @input="buscarConPausa" @focus="alEnfocar" @blur="cerrarLuego">
        <ul v-if="abierto" class="list-group shadow-sm" :style="estiloLista">
            <li v-if="buscando" class="list-group-item small text-muted py-1">
                <span class="spinner-border spinner-border-sm me-1"></span>Buscando…
            </li>
            <li v-else-if="resultados.length === 0" class="list-group-item small text-muted py-1">Sin coincidencias</li>
            <button v-for="p in resultados" :key="p.id" type="button" class="list-group-item list-group-item-action small py-1"
                @mousedown.prevent="elegir(p)">
                {{ nombreDe(p) }}
            </button>
            <button v-if="modelValue" type="button" class="list-group-item list-group-item-action small py-1 text-danger"
                @mousedown.prevent="elegir(null)">
                Quitar proveedor
            </button>
        </ul>
    </div>
</template>

<script setup lang="ts">
// Fila 13, fase 2b — buscador de proveedor para los servicios y vehículos de
// una salida. Busca en el servidor (proveedorService.listar con ?search=),
// así no depende de cuántos proveedores tenga la agencia. T-039: también en el
// cotizador y paquetes; al abrirlo muestra los primeros, y `tipoId` lo acota a
// un tipo (hoteles, mayoristas).
import { ref, watch } from 'vue';
import { useListaFlotante } from '@/composables/agencia-viajes/useListaFlotante';
import { proveedorService } from '@/services/admin/proveedorService';
import type { Proveedor } from '@/types/agencia-viajes';

const props = withDefaults(defineProps<{
    modelValue: number | null;
    tipoId?: number | null;
    // Nombre del proveedor ya elegido (para mostrarlo sin buscarlo).
    nombreActual?: string | null;
    placeholder?: string;
}>(), { nombreActual: null, placeholder: 'Buscar proveedor…' });

const emit = defineEmits<{
    (e: 'update:modelValue', id: number | null): void;
    (e: 'elegido', proveedor: Proveedor | null): void;
}>();

const nombreDe = (p: Proveedor) => p.nombre_comercial || p.razon_social;

// Nombre del proveedor elegido: lo que se vuelve a mostrar al salir sin elegir.
const nombreElegido = ref(props.nombreActual ?? '');
const idDelNombre = ref<number | null>(props.nombreActual ? props.modelValue : null);
const texto = ref(nombreElegido.value);
const resultados = ref<Proveedor[]>([]);
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
// T-039: un id ya guardado que no viene con su nombre (p. ej. editar una
// opción de mayorista) se busca por id, aunque no esté entre los primeros resultados.
watch(() => props.modelValue, async (id) => {
    if (!id) {
        if (!props.nombreActual) mostrar(null, '');
        return;
    }
    if (props.nombreActual || id === idDelNombre.value) return;
    try {
        const res = await proveedorService.obtener(id);
        mostrar(id, nombreDe(res.proveedor));
    } catch {
        // El buscador seguirá permitiendo cambiar el proveedor si el registro ya no existe.
    }
}, { immediate: true });

const buscar = async () => {
    const consulta = texto.value === nombreElegido.value ? '' : texto.value.trim();
    buscando.value = true;
    abierto.value = true;
    try {
        const res = await proveedorService.listar({ search: consulta || undefined, per_page: 15, estado: true, tipo_id: props.tipoId ?? undefined });
        resultados.value = res.proveedores ?? [];
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

const elegir = (p: Proveedor | null) => {
    mostrar(p?.id ?? null, p ? nombreDe(p) : '');
    abierto.value = false;
    emit('update:modelValue', p?.id ?? null);
    emit('elegido', p);
};

// Al salir sin elegir, vuelve a mostrar el proveedor que ya estaba.
const cerrarLuego = () => {
    setTimeout(() => {
        abierto.value = false;
        texto.value = nombreElegido.value;
    }, 150);
};
</script>
