<template>
    <b-modal v-model="abierto" title="Descripción en el comprobante" hide-footer centered>
        <div class="small text-muted mb-3">
            Producto del catálogo: <b>{{ tituloCatalogo }}</b>. El catálogo no cambia; esto vale solo para esta venta.
        </div>

        <label class="form-label small fw-semibold" for="desc-linea-txt">Descripción</label>
        <textarea id="desc-linea-txt" ref="areaTexto" v-model="texto" class="form-control form-control-sm" rows="5"
            :maxlength="MAX_DESCRIPCION_LINEA"></textarea>
        <div class="d-flex justify-content-between small mt-1">
            <button type="button" class="btn btn-link btn-sm p-0" @click="texto = tituloCatalogo">Volver al nombre del catálogo</button>
            <span :class="texto.length >= MAX_DESCRIPCION_LINEA ? 'text-danger fw-semibold' : 'text-muted'">
                {{ texto.length }} / {{ MAX_DESCRIPCION_LINEA }}
            </span>
        </div>

        <label class="form-label small fw-semibold mt-3">Así sale en el {{ nombreComprobante }}</label>
        <!-- Texto plano con saltos de línea (pre-line): nunca v-html. -->
        <div class="vista border rounded p-2 small">{{ texto.trim() === '' ? tituloCatalogo : texto.trim() }}</div>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="abierto = false">Cancelar</button>
            <button type="button" class="btn btn-sm btn-success" @click="aplicar">Aplicar</button>
        </div>
    </b-modal>
</template>

<script setup lang="ts">
// Ventana «Editar descripción» de una línea de venta (T-108). Reutilizable en edit.vue (T-110).
// El texto editado REEMPLAZA al nombre del catálogo en el XML, el PDF y el ticket (máx. 500 caracteres).
// Devuelve `null` cuando el texto queda vacío o igual al nombre del catálogo (= sin descripción editada).
import { nextTick, ref, watch } from 'vue';
import { MAX_DESCRIPCION_LINEA, descripcionAGuardar } from '@/helpers/ventaRetail';

const props = withDefaults(defineProps<{
    tituloCatalogo: string;
    /** Descripción editada actual de la línea (null/vacío = usa el nombre del catálogo). */
    descripcionActual?: string | null;
    nombreComprobante?: string;
}>(), { descripcionActual: null, nombreComprobante: 'comprobante' });

const abierto = defineModel<boolean>({ default: false });
const emit = defineEmits<{ (e: 'aplicar', descripcion: string | null): void }>();

const texto = ref('');
const areaTexto = ref<HTMLTextAreaElement | null>(null);

// Al abrir, empieza con el texto editado o, si no hay, con el nombre del catálogo.
watch(abierto, (visible) => {
    if (!visible) return;
    texto.value = props.descripcionActual?.trim() ? props.descripcionActual : props.tituloCatalogo;
    nextTick(() => areaTexto.value?.focus());
});

const aplicar = () => {
    emit('aplicar', descripcionAGuardar(texto.value, props.tituloCatalogo));
    abierto.value = false;
};
</script>

<style scoped>
.vista { white-space: pre-line; background: var(--bs-light-bg-subtle, #f8f9fa); min-height: 2.5rem; overflow-wrap: anywhere; }
</style>
