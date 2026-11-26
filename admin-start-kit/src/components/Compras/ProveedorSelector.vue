<template>
    <div class="position-relative">
        <div class="input-group input-group-sm">
            <input :id="id" v-model="texto" type="text" class="form-control" autocomplete="off" :placeholder="placeholder"
                @input="buscarConPausa" @focus="buscar" @blur="cerrar" />
            <button v-if="modelValue" type="button" class="btn btn-outline-secondary" title="Quitar proveedor" @click="elegir(null)">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div v-if="abierto" class="list-group position-absolute w-100 shadow" style="z-index: 1050; max-height: 260px; overflow-y: auto">
            <button v-for="p in opciones" :key="p.id" type="button" class="list-group-item list-group-item-action py-1"
                @mousedown.prevent="elegir(p)">
                <span class="small">{{ nombreConCiudad(p.razon_social, p.ciudad) }}</span>
                <small class="text-muted d-block">{{ p.numero_documento || 'Sin documento' }}</small>
            </button>
            <div v-if="!opciones.length" class="list-group-item small text-muted py-1">{{ mensaje }}</div>
        </div>
    </div>
</template>

<script setup lang="ts">
// T-135 — buscador de proveedores del sistema base (todos). La ciudad distingue dos
// establecimientos del mismo RUC.
import { ref, watch } from 'vue';
import httpClient from '@/helpers/http-client';
import { nombreConCiudad } from '@/helpers/proveedor';
import type { CompraProveedor } from '@/types/compras';

const props = withDefaults(defineProps<{ modelValue: CompraProveedor | null; id?: string; placeholder?: string }>(), {
    id: undefined,
    placeholder: 'Buscar por nombre o documento',
});
const emit = defineEmits<{ (e: 'update:modelValue', proveedor: CompraProveedor | null): void }>();

const texto = ref('');
const opciones = ref<CompraProveedor[]>([]);
const abierto = ref(false);
const mensaje = ref('Sin resultados.');
let espera: ReturnType<typeof setTimeout> | undefined;
let pedido = 0;

const etiqueta = (p: CompraProveedor | null) => (p ? nombreConCiudad(p.razon_social, p.ciudad) : '');

watch(() => props.modelValue, (p) => (texto.value = etiqueta(p)), { immediate: true });

const buscar = async () => {
    const actual = ++pedido;
    const search = texto.value === etiqueta(props.modelValue) ? '' : texto.value.trim();
    try {
        const { data } = await httpClient.get('proveedores-base', { params: { search: search || undefined, estado: 1, per_page: 20 } });
        // Una respuesta vieja no pisa a la nueva.
        if (actual !== pedido) return;
        opciones.value = data.proveedores;
        mensaje.value = 'Sin resultados.';
    } catch (error: any) {
        opciones.value = [];
        mensaje.value = error.response?.data?.message ?? 'No se pudo buscar.';
    }
    abierto.value = true;
};

const buscarConPausa = () => {
    clearTimeout(espera);
    espera = setTimeout(buscar, 300);
};

const elegir = (p: CompraProveedor | null) => {
    abierto.value = false;
    texto.value = etiqueta(p);
    emit('update:modelValue', p);
};

const cerrar = () => {
    clearTimeout(espera);
    pedido++;
    abierto.value = false;
    texto.value = etiqueta(props.modelValue);
};
</script>
