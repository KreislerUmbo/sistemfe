<template>
  <!-- T-153: historial de stock del producto (solo lectura). -->
  <b-card no-body class="mt-3">
    <b-card-header class="py-2">
      <h6 class="mb-0">Kárdex</h6>
    </b-card-header>
    <b-card-body class="p-2">
      <b-table-simple responsive small hover class="mb-2 align-middle">
        <b-thead>
          <b-tr>
            <b-th>Fecha</b-th>
            <b-th>Tipo</b-th>
            <b-th>Documento / motivo</b-th>
            <b-th class="text-end">Cantidad</b-th>
            <b-th class="text-end">Saldo</b-th>
            <b-th class="text-end">Costo unit.</b-th>
            <b-th>Usuario</b-th>
          </b-tr>
        </b-thead>
        <b-tbody>
          <b-tr v-for="m in movimientos" :key="m.id">
            <b-td class="text-nowrap">{{ formatFechaHora(m.created_at) }}</b-td>
            <b-td>{{ TIPOS_MOVIMIENTO[m.tipo] ?? m.tipo }}</b-td>
            <b-td>{{ m.documento ?? m.motivo }}</b-td>
            <b-td class="text-end" :class="m.cantidad < 0 ? 'text-danger' : 'text-success'">
              {{ m.cantidad > 0 ? '+' : '' }}{{ m.cantidad }}
            </b-td>
            <b-td class="text-end fw-semibold">{{ m.saldo }}</b-td>
            <b-td class="text-end text-nowrap">{{ m.costo_unitario != null ? `S/ ${m.costo_unitario}` : '—' }}</b-td>
            <b-td>{{ m.usuario ?? '—' }}</b-td>
          </b-tr>
          <b-tr v-if="!movimientos.length">
            <b-td colspan="7" class="text-center text-muted">{{ mensaje }}</b-td>
          </b-tr>
        </b-tbody>
      </b-table-simple>
      <b-pagination v-if="total > porPagina" v-model="pagina" :total-rows="total" :per-page="porPagina" size="sm"
        class="mb-0 justify-content-end" />
    </b-card-body>
  </b-card>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import httpClient from '@/helpers/http-client';
import { formatFechaHora } from '@/helpers/fecha';
import { TIPOS_MOVIMIENTO, type MovimientoStock } from '@/types/products';

const props = defineProps<{ productoId: number | string }>();

const movimientos = ref<MovimientoStock[]>([]);
const total = ref(0);
const porPagina = 20;
const pagina = ref(1);
const mensaje = ref('Cargando…');

const cargar = async () => {
  try {
    const res = await httpClient.get(`products/${props.productoId}/kardex`, {
      params: { page: pagina.value, per_page: porPagina },
    });
    movimientos.value = res.data.movimientos;
    total.value = res.data.total;
    mensaje.value = 'Sin movimientos de stock.';
  } catch {
    mensaje.value = 'No se pudo cargar el kárdex.';
  }
};

watch(pagina, cargar);
onMounted(cargar);

// Después de guardar la ficha: volver a la primera página, donde está el movimiento nuevo.
defineExpose({
  recargar: () => (pagina.value === 1 ? cargar() : (pagina.value = 1)),
});
</script>
