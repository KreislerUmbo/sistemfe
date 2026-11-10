<template>
    <div class="position-relative">
        <input ref="inputRef" type="text" class="form-control form-control-sm" :placeholder="placeholder"
            :disabled="disabled" v-model="texto" @input="buscarConPausa" @focus="alEnfocar" @blur="cerrarLuego"
            @keydown.enter.prevent="alEnter">
        <ul v-if="abierto" class="list-group shadow-sm" :style="estiloLista">
            <li v-if="buscando" class="list-group-item small text-muted py-1">
                <span class="spinner-border spinner-border-sm me-1"></span>Buscando…
            </li>
            <template v-else>
                <button v-for="a in resultados" :key="a.id" type="button"
                    class="list-group-item list-group-item-action small py-1" @mousedown.prevent="elegir(a.nombre)">
                    {{ a.nombre }}<span v-if="a.codigo_iata" class="text-muted ms-1">({{ a.codigo_iata }})</span>
                </button>
                <li v-if="!resultados.length && !puedeAgregar" class="list-group-item small text-muted py-1">Sin coincidencias</li>
                <button v-if="puedeAgregar" type="button" class="list-group-item list-group-item-action small py-1 text-primary"
                    @mousedown.prevent="agregar">
                    <i class="fas fa-plus me-1"></i>Agregar «{{ consulta }}»
                </button>
                <button v-if="modelValue" type="button" class="list-group-item list-group-item-action small py-1 text-danger"
                    @mousedown.prevent="elegir('')">
                    Quitar aerolínea
                </button>
            </template>
        </ul>
    </div>
</template>

<script setup lang="ts">
// T-016 — Buscador de aerolíneas sobre el catálogo común (T-015). Guarda el NOMBRE del catálogo
// (las columnas siguen siendo texto). Sugiere con pausa mientras se escribe; si lo escrito no
// existe ofrece "Agregar «X»" (POST aerolineas, que no duplica: devuelve la existente).
// Un valor ya guardado que no está en el catálogo se muestra tal cual hasta que se cambie.
import { computed, ref, watch } from 'vue';
import { useListaFlotante } from '@/composables/agencia-viajes/useListaFlotante';
import { aerolineaService, type AerolineaCatalogo } from '@/services/admin/aerolineaService';

const props = withDefaults(defineProps<{
    modelValue?: string | null;
    placeholder?: string;
    disabled?: boolean;
}>(), { placeholder: 'Buscar aerolínea…', disabled: false });

const emit = defineEmits<{ (e: 'update:modelValue', nombre: string): void }>();

const texto = ref(props.modelValue ?? '');
const resultados = ref<AerolineaCatalogo[]>([]);
const abierto = ref(false);
const buscando = ref(false);
const inputRef = ref<HTMLInputElement | null>(null);
const { estilo: estiloLista } = useListaFlotante(inputRef, abierto);
let pausa: ReturnType<typeof setTimeout> | null = null;
let ultimaBusqueda = 0;

watch(() => props.modelValue, (v) => { texto.value = v ?? ''; });

// Sin tildes ni mayúsculas, igual que Aerolinea::normalizar() en el servidor.
const normalizar = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim().toLowerCase();

// Lo escrito cuenta como búsqueda solo si cambió respecto del valor elegido.
const consulta = computed(() => (texto.value === (props.modelValue ?? '') ? '' : texto.value.replace(/\s+/g, ' ').trim()));
const puedeAgregar = computed(() =>
    consulta.value.length >= 2 && !resultados.value.some((a) => normalizar(a.nombre) === normalizar(consulta.value)));

const buscar = async () => {
    const id = ++ultimaBusqueda;
    buscando.value = true;
    abierto.value = true;
    try {
        const lista = await aerolineaService.buscar(consulta.value);
        if (id === ultimaBusqueda) resultados.value = lista;
    } catch {
        if (id === ultimaBusqueda) resultados.value = [];
    } finally {
        if (id === ultimaBusqueda) buscando.value = false;
    }
};

const buscarConPausa = () => {
    if (pausa) clearTimeout(pausa);
    pausa = setTimeout(buscar, 300);
};

const alEnfocar = (e: FocusEvent) => {
    (e.target as HTMLInputElement).select();
    buscar();
};

const elegir = (nombre: string) => {
    texto.value = nombre;
    abierto.value = false;
    emit('update:modelValue', nombre);
};

const agregar = async () => {
    try {
        const res = await aerolineaService.agregar(consulta.value);
        elegir(res.data.nombre);
    } catch {
        // Sin permiso o sin conexión: el campo vuelve al valor anterior al salir.
    }
};

// Enter: elige la única sugerencia o la coincidencia exacta; si no, agrega lo escrito.
const alEnter = () => {
    if (buscando.value || !consulta.value) return;
    const exacta = resultados.value.find((a) => normalizar(a.nombre) === normalizar(consulta.value));
    if (exacta) elegir(exacta.nombre);
    else if (resultados.value.length === 1) elegir(resultados.value[0].nombre);
    else if (puedeAgregar.value) agregar();
};

// Al salir sin elegir, vuelve el valor que ya estaba.
const cerrarLuego = () => {
    setTimeout(() => {
        abierto.value = false;
        texto.value = props.modelValue ?? '';
    }, 150);
};
</script>
