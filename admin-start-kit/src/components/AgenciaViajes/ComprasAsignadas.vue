<template>
    <div v-if="visible" class="card border mb-3">
        <div class="card-header py-2"><h6 class="mb-0 small fw-semibold">Compras</h6></div>
        <div class="card-body py-2 small">
            <div v-if="error" class="text-danger">{{ error }}</div>
            <div v-else-if="!compras.length" class="text-muted fst-italic">Sin compras asignadas.</div>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Serie-número</th>
                            <th>Proveedor</th>
                            <th class="text-end">Asignado</th>
                            <th class="text-end">Total del comprobante</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in compras" :key="c.compra_id">
                            <td>{{ formatFecha(c.fecha_emision) }}</td>
                            <td>{{ c.tipo_nombre }}</td>
                            <td>
                                <router-link :to="{ name: 'compras.index', query: { ver: c.compra_id } }">
                                    {{ c.serie }}-{{ c.numero }}
                                </router-link>
                            </td>
                            <td>{{ nombreConCiudad(c.proveedor?.razon_social ?? '', c.proveedor?.ciudad) }}</td>
                            <td class="text-end fw-semibold">{{ formatMonto(c.monto, c.moneda) }}</td>
                            <td class="text-end">{{ formatMonto(c.total, c.moneda) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';
import httpClient from '@/helpers/http-client';
import { formatMonto } from '@/helpers/compras';
import { formatFecha } from '@/helpers/fecha';
import { nombreConCiudad } from '@/helpers/proveedor';
import { useAuthStore } from '@/stores/auth';
import type { CompraDeFicha } from '@/types/compras';

// T-152 — bloque «Compras» de la ficha de una reserva o de una salida. No aparece sin
// compras.ver ni con el módulo apagado.
const props = defineProps<{ destino: 'reserva' | 'salida'; id: number }>();

const authStore = useAuthStore();
const visible = ref(false);
const compras = ref<CompraDeFicha[]>([]);
const error = ref('');

watch(
    () => [props.destino, props.id] as const,
    async ([destino, id]) => {
        visible.value = false;
        error.value = '';
        compras.value = [];
        if (!authStore.isPermitedRoute('compras.ver')) return;

        const ruta = destino === 'reserva' ? 'reservas' : 'salidas-operativas';
        try {
            const { data } = await httpClient.get(`${ruta}/${id}/compras`);
            compras.value = data.compras;
            visible.value = true;
        } catch (e: any) {
            // 403: módulo apagado o sin permiso. El resto sí se avisa.
            if (e.response?.status !== 403) {
                error.value = e.response?.data?.message ?? 'No se pudieron cargar las compras.';
                visible.value = true;
            }
        }
    },
    { immediate: true },
);
</script>
