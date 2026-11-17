<template>
    <!-- Celda «Afectación» de una fila de "Qué entra en este comprobante"
         (T-091, D-AGE-037) o de una línea de Venta directa (T-048). Editable: lista Gravado / Exonerado – Amazonía /
         Inafecto. Si no (tour, servicio desmarcado o ya facturado): el texto,
         o «Mixto» en un tour con líneas de afectación distinta. -->
    <div class="text-nowrap">
        <select v-if="fila.editable" class="form-select form-select-sm" style="min-width:11rem"
            :value="fila.tip_afe_igv" :disabled="deshabilitado" :aria-label="`Afectación de ${fila.nombre}`"
            @change="alCambiar(($event.target as HTMLSelectElement).value)">
            <option v-for="op in OPCIONES_AFECTACION" :key="op.valor" :value="op.valor">{{ op.texto }}</option>
        </select>
        <span v-else :class="{ 'text-muted': fila.excluido }"
            :title="fila.tip_afe_igv === 'mixto' ? 'Un tour con servicios de afectación distinta: se corrige en el tour o con «Corregir tratamiento» de la reserva' : undefined">
            {{ textoAfectacion(fila.tip_afe_igv) }}
        </span>
        <div v-if="fila.cambiado" class="text-warning-emphasis" style="font-size:.75rem" title="Afectación cambiada a mano">✎ cambiado</div>
    </div>
</template>

<script setup lang="ts">
// Solo dibuja y avisa: quien guarda es el modal (useAfectacionFactura), que
// vuelve a pedir la vista previa para refrescar los totales. T-048: también la
// usa Venta directa, que manda el cambio en su vista previa (no guarda nada aparte).
import type { AfectacionFila, FilaAfectacion } from '@/services/admin/reservaFacturacionService';

defineProps<{
    fila: FilaAfectacion;
    // Mientras se guarda un cambio, ninguna lista se puede tocar.
    deshabilitado?: boolean;
}>();

const emit = defineEmits<{
    cambiar: [tipAfeIgv: '10' | '20' | '30'];
}>();

const OPCIONES_AFECTACION: Array<{ valor: '10' | '20' | '30'; texto: string }> = [
    { valor: '10', texto: 'Gravado' },
    { valor: '20', texto: 'Exonerado – Amazonía' },
    { valor: '30', texto: 'Inafecto' },
];

const textoAfectacion = (codigo: AfectacionFila) =>
    codigo === 'mixto' ? 'Mixto' : (OPCIONES_AFECTACION.find((op) => op.valor === codigo)?.texto ?? codigo);

const alCambiar = (valor: string) => {
    if (valor === '10' || valor === '20' || valor === '30') emit('cambiar', valor);
};
</script>
