<template>
    <div class="position-relative">
        <input type="text" class="form-control form-control-sm" :placeholder="placeholder"
            v-model="texto" @input="buscarConPausa" @focus="abierto = resultados.length > 0" @blur="cerrarLuego">
        <ul v-if="abierto" class="list-group position-absolute w-100 shadow-sm" style="z-index:1060;max-height:220px;overflow-y:auto">
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
// así no depende de cuántos proveedores tenga la agencia.
import { ref, watch } from 'vue';
import { proveedorService } from '@/services/admin/proveedorService';
import type { Proveedor } from '@/types/agencia-viajes';

const props = withDefaults(defineProps<{
    modelValue: number | null;
    // Nombre del proveedor ya elegido (para mostrarlo sin buscarlo).
    nombreActual?: string | null;
    placeholder?: string;
}>(), { nombreActual: null, placeholder: 'Buscar proveedor…' });

const emit = defineEmits<{
    (e: 'update:modelValue', id: number | null): void;
    (e: 'elegido', proveedor: Proveedor | null): void;
}>();

const texto = ref(props.nombreActual ?? '');
const resultados = ref<Proveedor[]>([]);
const abierto = ref(false);
const buscando = ref(false);
let pausa: ReturnType<typeof setTimeout> | null = null;

watch(() => props.nombreActual, (nombre) => { texto.value = nombre ?? ''; });

const nombreDe = (p: Proveedor) => p.nombre_comercial || p.razon_social;

const buscar = async () => {
    const consulta = texto.value.trim();
    if (consulta.length < 2) {
        resultados.value = [];
        abierto.value = false;
        return;
    }
    buscando.value = true;
    abierto.value = true;
    try {
        const res = await proveedorService.listar({ search: consulta, per_page: 15, estado: true });
        resultados.value = res.proveedores ?? [];
    } finally {
        buscando.value = false;
    }
};

const buscarConPausa = () => {
    if (pausa) clearTimeout(pausa);
    pausa = setTimeout(buscar, 300);
};

const elegir = (p: Proveedor | null) => {
    texto.value = p ? nombreDe(p) : '';
    abierto.value = false;
    emit('update:modelValue', p?.id ?? null);
    emit('elegido', p);
};

// Al salir sin elegir, vuelve a mostrar el proveedor que ya estaba.
const cerrarLuego = () => {
    setTimeout(() => {
        abierto.value = false;
        texto.value = props.nombreActual ?? '';
    }, 150);
};
</script>
