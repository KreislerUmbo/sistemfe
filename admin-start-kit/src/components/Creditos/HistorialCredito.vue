<template>
  <div>
    <p v-if="!eventos.length" class="text-muted text-center py-4 mb-0">Sin movimientos especiales.</p>
    <ul v-else class="list-group list-group-flush">
      <li v-for="(e, i) in eventos" :key="i" class="list-group-item py-2 small">
        <div class="d-flex justify-content-between gap-2">
          <span class="fw-semibold"><i :class="e.icono" class="me-2 text-muted"></i>{{ e.titulo }}</span>
          <small class="text-muted text-nowrap">{{ e.fecha }}</small>
        </div>
        <div class="small text-muted mt-1">{{ e.detalle }}</div>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos: condonaciones, cargos, castigos y reprogramaciones del estado de cuenta.
import { computed } from 'vue'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import type { EstadoCuenta } from '@/types/creditos'

const props = defineProps<{ estado: EstadoCuenta }>()

const eventos = computed(() => [
  ...props.estado.reprogramaciones.map((r) => ({
    icono: 'fas fa-calendar-alt', titulo: 'Reprogramación', fecha: formatoFecha(r.fecha),
    detalle: `${r.cuotas.length} cuota(s) · ${r.motivo}${r.cargo !== '0.00' ? ` · cargo ${formatoSoles(r.cargo)}` : ''}`,
  })),
  ...props.estado.condonaciones.map((c) => ({
    icono: 'fas fa-hand-holding-heart', titulo: `Condonación de mora${c.estado !== 'vigente' ? ' (anulada)' : ''}`, fecha: formatoFecha(c.fecha),
    detalle: `Cuota #${c.numero_cuota} · ${formatoSoles(c.monto)} · ${c.motivo}`,
  })),
  ...props.estado.castigos.map((c) => ({
    icono: 'fas fa-gavel', titulo: c.fecha_reversion ? 'Castigo revertido' : 'Crédito castigado', fecha: formatoFecha(c.fecha_castigo),
    detalle: `${c.tipo === 'automatico' ? 'Automático por atraso' : c.motivo ?? ''}${c.fecha_reversion ? ` · revertido el ${formatoFecha(c.fecha_reversion)}` : ''}`,
  })),
])
</script>
