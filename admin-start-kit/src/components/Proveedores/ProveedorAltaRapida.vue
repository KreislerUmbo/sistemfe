<template>
    <b-modal v-if="puedeGestionar" :model-value="modelValue" @update:model-value="(v: boolean) => emit('update:modelValue', v)"
        title="Alta rápida de proveedor" hide-footer centered size="lg">
        <div class="row g-2">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input id="alta-no-domiciliado" v-model="form.es_no_domiciliado" class="form-check-input" type="checkbox">
                    <label class="form-check-label small" for="alta-no-domiciliado">No domiciliado (del extranjero)</label>
                </div>
            </div>

            <template v-if="form.es_no_domiciliado">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="alta-pais">País *</label>
                    <input id="alta-pais" v-model="form.pais" type="text" class="form-control form-control-sm" maxlength="80">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="alta-tipo-ext">Tipo de documento</label>
                    <input id="alta-tipo-ext" v-model="form.tipo_documento" type="text" class="form-control form-control-sm"
                        maxlength="20" placeholder="NIT, CNPJ, Tax ID…">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="alta-numero-ext">Número</label>
                    <input id="alta-numero-ext" v-model="form.numero_documento" type="text" class="form-control form-control-sm" maxlength="30">
                </div>
            </template>
            <template v-else>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="alta-tipo">Documento</label>
                    <select id="alta-tipo" v-model="form.tipo_documento" class="form-select form-select-sm">
                        <option value="RUC">RUC</option>
                        <option value="DNI">DNI</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small mb-1" for="alta-numero">Número</label>
                    <div class="input-group input-group-sm">
                        <input id="alta-numero" v-model.trim="form.numero_documento" type="text" class="form-control form-control-sm"
                            maxlength="11" inputmode="numeric">
                        <button v-if="form.tipo_documento === 'RUC'" type="button" class="btn btn-outline-primary" title="Buscar en SUNAT"
                            :disabled="buscando || !esRuc(form.numero_documento)" @click="consultar">
                            <span v-if="buscando" class="spinner-border spinner-border-sm"></span>
                            <i v-else class="fas fa-search"></i>
                        </button>
                    </div>
                    <small v-if="avisoConsulta" class="text-warning d-block">{{ avisoConsulta }}</small>
                </div>
            </template>

            <div class="col-md-7">
                <label class="form-label small mb-1" for="alta-razon">Razón social *</label>
                <input id="alta-razon" v-model="form.razon_social" type="text" class="form-control form-control-sm" maxlength="250">
            </div>
            <div class="col-md-5">
                <label class="form-label small mb-1" for="alta-comercial">Nombre comercial</label>
                <input id="alta-comercial" v-model="form.nombre_comercial" type="text" class="form-control form-control-sm" maxlength="250">
            </div>
            <div class="col-md-8">
                <label class="form-label small mb-1" for="alta-dir-fiscal">Dirección fiscal</label>
                <input id="alta-dir-fiscal" v-model="form.direccion_fiscal" type="text" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1" for="alta-ciudad">Ciudad</label>
                <input id="alta-ciudad" v-model="form.ciudad" type="text" class="form-control form-control-sm" maxlength="120">
            </div>
            <div class="col-md-8">
                <label class="form-label small mb-1" for="alta-dir-local">Dirección del local (si es distinta)</label>
                <input id="alta-dir-local" v-model="form.direccion" type="text" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1" for="alta-telefono">Teléfono</label>
                <input id="alta-telefono" v-model="form.telefono" type="text" class="form-control form-control-sm" maxlength="50">
                <small v-if="!form.telefono.trim()" class="text-warning d-block">Sin teléfono: no habrá cómo escribirle.</small>
            </div>

            <div v-if="existentes.length" class="col-12">
                <AvisoDocumentoRepetido :existentes="existentes" :guardando="guardando" @usar="usarExistente" @crear="guardar(true)" />
            </div>

            <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-sm btn-secondary" @click="emit('update:modelValue', false)">Cerrar</button>
                <button type="button" class="btn btn-sm btn-primary" :disabled="guardando || existentes.length > 0" @click="guardar(false)">
                    Registrar
                </button>
            </div>
        </div>
    </b-modal>
</template>

<script setup lang="ts">
// T-134 — alta de un proveedor en segundos (hoy desde /proveedores; Compras la monta en T-135).
import { computed, reactive, ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import AvisoDocumentoRepetido from '@/components/Proveedores/AvisoDocumentoRepetido.vue';
import httpClient from '@/helpers/http-client';
import { datosDeConsultaRuc, esRuc } from '@/helpers/proveedor';
import { useAuthStore } from '@/stores/auth';
import type { ProveedorBase, ProveedorExistente } from '@/types/cash';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{ modelValue: boolean }>();
const emit = defineEmits<{
    (e: 'update:modelValue', abierto: boolean): void;
    (e: 'creado', proveedor: ProveedorBase): void;
    // El usuario dijo que el proveedor ya existía: quien monta el componente decide qué hacer.
    (e: 'existente', proveedor: ProveedorExistente): void;
}>();

const authStore = useAuthStore();
const puedeGestionar = computed(() => authStore.isPermitedRoute('proveedores.gestionar'));

const vacio = () => ({
    es_no_domiciliado: false,
    pais: '',
    tipo_documento: 'RUC',
    numero_documento: '',
    razon_social: '',
    nombre_comercial: '',
    direccion_fiscal: '',
    ciudad: '',
    direccion: '',
    telefono: '',
});
const form = reactive(vacio());

const buscando = ref(false);
const guardando = ref(false);
const avisoConsulta = ref('');
const existentes = ref<ProveedorExistente[]>([]);
let autollenado = false;

const consultar = async () => {
    const numero = form.numero_documento;
    if (!esRuc(numero)) return;

    buscando.value = true;
    avisoConsulta.value = '';
    try {
        const { data } = await httpClient.get(`/search-document/ruc/${numero}`);
        // Si el número cambió mientras respondía, la respuesta ya no sirve.
        if (numero !== form.numero_documento) return;
        const datos = datosDeConsultaRuc(data);
        if (!datos) {
            avisoConsulta.value = 'No se encontró ese RUC. Completa los datos a mano.';
            return;
        }
        Object.assign(form, datos);
        autollenado = true;
    } catch {
        if (numero === form.numero_documento) {
            avisoConsulta.value = 'No se pudo consultar el RUC. Completa los datos a mano.';
        }
    } finally {
        buscando.value = false;
    }
};

watch(() => form.numero_documento, () => {
    existentes.value = [];
    avisoConsulta.value = '';
    // Los datos traídos eran del RUC anterior.
    if (autollenado) {
        Object.assign(form, { razon_social: '', nombre_comercial: '', direccion_fiscal: '', ciudad: '' });
        autollenado = false;
    }
    if (!form.es_no_domiciliado && form.tipo_documento === 'RUC' && esRuc(form.numero_documento)) consultar();
});

watch(() => form.es_no_domiciliado, (noDomiciliado) => {
    form.numero_documento = '';
    form.tipo_documento = noDomiciliado ? '' : 'RUC';
    form.pais = '';
});
watch(() => form.pais, () => (existentes.value = []));

watch(() => props.modelValue, (abierto) => {
    if (!abierto) return;
    Object.assign(form, vacio());
    autollenado = false;
    existentes.value = [];
    avisoConsulta.value = '';
});

const guardar = async (confirmado: boolean) => {
    if (!form.razon_social.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'La razón social es obligatoria.', 'error');
        return;
    }

    guardando.value = true;
    try {
        const { data } = await httpClient.post('proveedores-base', {
            ...form,
            tipo_documento: form.numero_documento ? form.tipo_documento || null : null,
            confirmar_duplicado: confirmado,
        });
        emit('update:modelValue', false);
        emit('creado', data.proveedor);
    } catch (error: any) {
        if (error.response?.data?.duplicado) {
            existentes.value = error.response.data.existentes;
            return;
        }
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo registrar.', 'error');
    } finally {
        guardando.value = false;
    }
};

const usarExistente = (proveedor: ProveedorExistente) => {
    emit('update:modelValue', false);
    emit('existente', proveedor);
};
</script>
