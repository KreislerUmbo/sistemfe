<template>
    <Teleport to="body">
        <div v-if="visible" class="aviso-venta" role="status">
            <span>{{ texto }}</span>
            <button v-for="(a, i) in acciones" :key="i" type="button" @click="ejecutar(a)">{{ a.texto }}</button>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
// Aviso flotante sin ventana que cerrar (T-106): «Quitaste X · Deshacer», «Boleta B001-… guardada · Reimprimir».
// Solo texto plano (sin v-html).
import { onBeforeUnmount, ref } from 'vue';

export type AccionAviso = { texto: string; fn: () => void };

const visible = ref(false);
const texto = ref('');
const acciones = ref<AccionAviso[]>([]);
let temporizador: ReturnType<typeof setTimeout> | null = null;

const mostrar = (contenido: string, lista: AccionAviso[] = [], ms = 6000) => {
    texto.value = contenido;
    acciones.value = lista;
    visible.value = true;
    if (temporizador) clearTimeout(temporizador);
    temporizador = setTimeout(() => { visible.value = false; }, ms);
};
const ejecutar = (a: AccionAviso) => { visible.value = false; a.fn(); };
onBeforeUnmount(() => { if (temporizador) clearTimeout(temporizador); });

defineExpose({ mostrar });
</script>

<style scoped>
.aviso-venta { position: fixed; left: 50%; transform: translateX(-50%); bottom: 18px; z-index: 2000; background: #0f2a1d; color: #fff;
    border-radius: 10px; padding: 12px 16px; display: flex; gap: 14px; align-items: center; flex-wrap: wrap;
    box-shadow: 0 10px 30px rgba(0, 0, 0, .25); width: max-content; max-width: calc(100% - 32px); }
.aviso-venta button { background: none; border: 0; color: inherit; text-decoration: underline; cursor: pointer; font-weight: 600; padding: 0; }
</style>
