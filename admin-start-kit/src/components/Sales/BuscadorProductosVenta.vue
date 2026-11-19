<template>
    <div class="buscador-venta position-relative">
        <i class="fas fa-search lupa text-muted"></i>
        <input ref="entrada" type="text" class="form-control form-control-lg ps-5 pe-5" v-model="texto"
            placeholder="Buscar producto por nombre, código o escanear" autocomplete="off" :disabled="deshabilitado"
            @input="alEscribir" @keydown="alTeclear" @blur="alSalir" @focus="alEnfocar" />
        <kbd class="atajo">F2</kbd>

        <div v-if="abierto && texto.trim().length >= 2" class="list-group sugerencias shadow">
            <button v-for="(p, i) in resultados" :key="p.id" type="button"
                class="list-group-item list-group-item-action d-flex justify-content-between gap-3"
                :class="{ active: i === activo }" @mousedown.prevent="elegir(p)">
                <span>
                    <span class="fw-medium">{{ p.title }}</span><br>
                    <small :class="i === activo ? '' : 'text-muted'">{{ p.sku }}{{ textoStock(p) }}</small>
                </span>
                <span class="fw-semibold text-nowrap">{{ moneda === 'USD' ? 'US$' : 'S/' }} {{ Number(p.price_general ||
                    0).toFixed(2) }}</span>
            </button>
            <div v-if="!buscando && resultados.length === 0" class="list-group-item text-muted small">
                No se encontró «{{ texto.trim() }}».
            </div>
            <button type="button" class="list-group-item list-group-item-action text-success fw-medium"
                @mousedown.prevent="crear">
                + Crear producto o servicio «{{ texto.trim() }}»
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
// Buscador fijo de la pantalla de venta (T-106). Busca en `products?search=` (nombre o SKU; el código de
// barras es el SKU). F2 lo enfoca; flechas + Enter agregan; Enter con un código exacto agrega directo.
// Reutilizable por la pantalla de edición (T-110): no sabe nada de la venta, solo emite el producto elegido.
import { onBeforeUnmount, onMounted, ref } from 'vue';
import httpClient from '@/helpers/http-client';
import type { Product } from '@/types/products';

defineProps<{ moneda: string; deshabilitado?: boolean }>();
const emit = defineEmits<{ (e: 'agregar', producto: Product): void; (e: 'crear', texto: string): void }>();

const entrada = ref<HTMLInputElement | null>(null);
const texto = ref('');
const resultados = ref<Product[]>([]);
const activo = ref(0);
const abierto = ref(false);
const buscando = ref(false);
let temporizador: ReturnType<typeof setTimeout> | null = null;
let pedido = 0; // descarta respuestas viejas

const buscar = async (q: string): Promise<Product[]> => {
    const mio = ++pedido;
    buscando.value = true;
    try {
        const res = await httpClient.get(`products?search=${encodeURIComponent(q)}&take=10`);
        if (mio === pedido) {
            resultados.value = res.data.products.data;
            activo.value = 0;
        }
        return res.data.products.data;
    } catch {
        if (mio === pedido) resultados.value = [];
        return [];
    } finally {
        if (mio === pedido) buscando.value = false;
    }
};

const alEscribir = () => {
    if (temporizador) clearTimeout(temporizador);
    abierto.value = true;
    const q = texto.value.trim();
    if (q.length < 2) { resultados.value = []; return; }
    temporizador = setTimeout(() => buscar(q), 250);
};

const alEnfocar = () => {
    if (texto.value.trim().length >= 2) abierto.value = true;
};
const alSalir = () => setTimeout(() => { abierto.value = false; }, 150);

const textoStock = (p: Product) => (p.disponiblidad === 1 ? ' · sin control de stock' : ` · stock ${p.stock}`);

const limpiar = () => {
    texto.value = '';
    resultados.value = [];
    abierto.value = false;
    activo.value = 0;
};

const elegir = (p: Product) => {
    emit('agregar', p);
    limpiar();
    entrada.value?.focus();
};

const crear = () => {
    const t = texto.value.trim();
    limpiar();
    emit('crear', t);
};

// Enter: código exacto (lector de barras) → agrega directo; si no, el resaltado.
// La búsqueda puede no haber terminado cuando el lector manda Enter: se busca de nuevo ahí mismo.
const alEnter = async () => {
    const q = texto.value.trim();
    if (!q) return;
    if (temporizador) clearTimeout(temporizador);
    const lista = await buscar(q);
    const exacto = lista.find((p) => (p.sku ?? '').toLowerCase() === q.toLowerCase());
    const elegido = exacto ?? lista[activo.value] ?? (lista.length === 1 ? lista[0] : undefined);
    if (elegido) elegir(elegido);
};

const alTeclear = (e: KeyboardEvent) => {
    if (e.key === 'ArrowDown') { activo.value = Math.min(activo.value + 1, resultados.value.length - 1); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { activo.value = Math.max(activo.value - 1, 0); e.preventDefault(); }
    else if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) { e.preventDefault(); alEnter(); }
    else if (e.key === 'Escape') { abierto.value = false; }
};

const atajoF2 = (e: KeyboardEvent) => {
    if (e.key === 'F2') { e.preventDefault(); entrada.value?.focus(); }
};
onMounted(() => document.addEventListener('keydown', atajoF2));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', atajoF2);
    if (temporizador) clearTimeout(temporizador);
});

defineExpose({ enfocar: () => entrada.value?.focus() });
</script>

<style scoped>
.lupa { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 2; }
.atajo { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); z-index: 2; }
.sugerencias { position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 1050; max-height: 320px; overflow-y: auto; }
</style>
