<template>
    <div>
        <div v-if="modelValue" class="d-flex justify-content-between align-items-center border rounded px-2 py-1 small bg-light">
            <span><i class="fas fa-user-tie me-1 text-muted"></i>{{ modelValue.full_name }} — {{ modelValue.n_document }}</span>
            <button type="button" class="btn btn-sm btn-link p-0" @click="emit('update:modelValue', null)">Cambiar</button>
        </div>
        <template v-else>
            <div class="input-group input-group-sm">
                <input type="text" class="form-control" placeholder="Buscar cliente por nombre, DNI o RUC..."
                    v-model="busqueda" @input="buscar">
                <!-- Cliente nuevo sin salir del modal: el padre abre ClientFormQuick
                     (búsqueda de RUC/DNI vía apisperu o alta a mano). -->
                <button type="button" class="btn btn-outline-primary" @click="emit('nuevo', busqueda.trim())">
                    <i class="fas fa-plus me-1"></i>Nuevo
                </button>
            </div>
            <div v-if="resultados.length > 0" class="border rounded mt-1" style="max-height:140px;overflow-y:auto">
                <button v-for="c in resultados" :key="c.id" type="button"
                    class="dropdown-item small w-100 text-start" @click="elegir(c)">
                    {{ c.full_name }} — {{ c.n_document }}
                </button>
            </div>
            <small v-else-if="busqueda.trim().length >= 2" class="text-muted d-block mt-1">
                Sin resultados entre los clientes registrados — usa "Nuevo" para buscar por RUC/DNI o registrarlo a mano.
            </small>
        </template>
    </div>
</template>

<script setup lang="ts">
// Elegir a nombre de quién sale un comprobante (facturar una reserva, cobrar
// un anticipo). Extraído de reservas/detalle.vue (F1, 23-oct-2026) para usarlo
// en los dos modales. Busca en `clients?search=`, el mismo endpoint de Ventas.
import { ref } from 'vue';
import httpClient from '@/helpers/http-client';

export type ClienteComprobante = { id: number; full_name: string; n_document: string; cod_tipo_doc_sunat?: string };

defineProps<{ modelValue: ClienteComprobante | null }>();
const emit = defineEmits<{
    (e: 'update:modelValue', cliente: ClienteComprobante | null): void;
    // Lo tipeado en el buscador, para precargar el alta rápida (p. ej. un RUC).
    (e: 'nuevo', texto: string): void;
}>();

const busqueda = ref('');
const resultados = ref<ClienteComprobante[]>([]);
let pausa: ReturnType<typeof setTimeout> | undefined;

const buscar = () => {
    clearTimeout(pausa);
    const consulta = busqueda.value.trim();
    if (consulta.length < 2) {
        resultados.value = [];
        return;
    }
    pausa = setTimeout(async () => {
        try {
            const res = await httpClient.get(`clients?search=${encodeURIComponent(consulta)}&take=10`);
            resultados.value = res.data.clients.data;
        } catch {
            resultados.value = [];
        }
    }, 300);
};

const elegir = (c: ClienteComprobante) => {
    resultados.value = [];
    busqueda.value = '';
    emit('update:modelValue', c);
};
</script>
