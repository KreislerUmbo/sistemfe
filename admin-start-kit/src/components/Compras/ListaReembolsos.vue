<template>
    <!-- T-140 — reembolsos en la ficha de la compra o del pago. Los anulados se ven: nada se borra. -->
    <div class="mb-2">
        <h6 class="small fw-semibold mb-1">Reembolsos</h6>
        <table class="table table-sm small mb-0">
            <tbody>
                <tr v-for="r in reembolsos" :key="r.id" :class="{ 'text-muted': r.estado === 'anulado' }">
                    <td>{{ formatFecha(r.fecha) }}</td>
                    <td>
                        {{ r.origen === 'caja' ? `Caja · ${r.metodo_pago}` : `${r.cuenta} · op. ${r.numero_operacion}` }}
                        <small v-if="r.registrado_por" class="d-block text-muted">Registrado por {{ r.registrado_por }}</small>
                        <small v-if="r.estado === 'anulado'" class="d-block text-danger">
                            Anulado{{ r.anulado_por ? ` por ${r.anulado_por}` : '' }}: {{ r.motivo_anulacion }}
                        </small>
                    </td>
                    <td class="text-end" :class="{ 'text-decoration-line-through': r.estado === 'anulado' }">
                        {{ formatMonto(r.monto, r.moneda) }}
                        <small v-if="r.tipo_cambio" class="d-block text-muted">T.C. {{ Number(r.tipo_cambio) }}</small>
                    </td>
                    <td class="text-end">
                        <button v-if="puedeAnular && r.estado !== 'anulado'" type="button" class="btn btn-sm btn-link p-0"
                            @click="emit('anular', r)">Anular</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { formatMonto } from '@/helpers/compras';
import { formatFecha } from '@/helpers/fecha';
import { useAuthStore } from '@/stores/auth';
import type { Reembolso } from '@/types/pagosProveedor';

defineProps<{ reembolsos: Reembolso[] }>();
const emit = defineEmits<{ (e: 'anular', reembolso: Reembolso): void }>();

const authStore = useAuthStore();
const puedeAnular = computed(() => authStore.isPermitedRoute('compras.anular'));
</script>
