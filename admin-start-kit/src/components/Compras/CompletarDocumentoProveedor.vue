<template>
    <b-modal :model-value="modelValue" @update:model-value="(v: boolean) => emit('update:modelValue', v)"
        title="Completar el documento del proveedor" hide-footer centered>
        <p class="small text-muted mb-2">{{ motivo }}</p>
        <p v-if="!puedeGestionar" class="small text-danger">No tienes permiso para editar proveedores. Pídelo a un administrador.</p>
        <div v-else-if="cargando" class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>
        <div v-else class="row g-2">
            <div class="col-12">
                <label class="form-label small mb-1" for="doc-razon">Razón social *</label>
                <input id="doc-razon" v-model="form.razon_social" type="text" class="form-control form-control-sm" maxlength="250">
            </div>
            <div class="col-md-5">
                <label class="form-label small mb-1" for="doc-tipo">Documento</label>
                <input v-if="form.es_no_domiciliado" id="doc-tipo" v-model="form.tipo_documento" type="text"
                    class="form-control form-control-sm" maxlength="20">
                <select v-else id="doc-tipo" v-model="form.tipo_documento" class="form-select form-select-sm">
                    <option value="RUC">RUC</option>
                    <option value="DNI">DNI</option>
                </select>
            </div>
            <div class="col-md-7">
                <label class="form-label small mb-1" for="doc-numero">Número *</label>
                <input id="doc-numero" v-model.trim="form.numero_documento" type="text" class="form-control form-control-sm" maxlength="30">
            </div>
            <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-sm btn-secondary" @click="emit('update:modelValue', false)">Cerrar</button>
                <button type="button" class="btn btn-sm btn-primary" :disabled="guardando || !form.numero_documento" @click="guardar">
                    Guardar documento
                </button>
            </div>
        </div>
    </b-modal>
</template>

<script setup lang="ts">
// T-135 (D5) — «se pide completar el RUC en la primera compra».
import { computed, reactive, ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import { useAuthStore } from '@/stores/auth';
import type { CompraProveedor } from '@/types/compras';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{ modelValue: boolean; proveedorId: number | null; motivo: string }>();
const emit = defineEmits<{
    (e: 'update:modelValue', abierto: boolean): void;
    (e: 'guardado', proveedor: CompraProveedor): void;
}>();

const authStore = useAuthStore();
const puedeGestionar = computed(() => authStore.isPermitedRoute('proveedores.gestionar'));
const form = reactive({ razon_social: '', tipo_documento: 'RUC', numero_documento: '', es_no_domiciliado: false });
const guardando = ref(false);
// Hasta que llegan los datos del proveedor no se muestra el formulario: llegarían a pisar lo escrito.
const cargando = ref(false);

const mostrarError = (error: any) =>
    (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar.', 'error');

watch(() => props.modelValue, async (abierto) => {
    if (!abierto || !props.proveedorId) return;
    cargando.value = true;
    try {
        const { data } = await httpClient.get(`proveedores-base/${props.proveedorId}`);
        const p = data.proveedor;
        Object.assign(form, {
            razon_social: p.razon_social,
            tipo_documento: p.tipo_documento || (p.es_no_domiciliado ? '' : 'RUC'),
            numero_documento: p.numero_documento ?? '',
            es_no_domiciliado: p.es_no_domiciliado,
        });
    } catch (error) {
        // Sin los datos no se puede editar: se cierra.
        emit('update:modelValue', false);
        mostrarError(error);
    } finally {
        cargando.value = false;
    }
});

const guardar = async () => {
    guardando.value = true;
    try {
        const { data } = await httpClient.put(`proveedores-base/${props.proveedorId}`, {
            razon_social: form.razon_social,
            tipo_documento: form.tipo_documento || null,
            numero_documento: form.numero_documento,
        });
        emit('update:modelValue', false);
        emit('guardado', data.proveedor);
    } catch (error) {
        mostrarError(error);
    } finally {
        guardando.value = false;
    }
};
</script>
