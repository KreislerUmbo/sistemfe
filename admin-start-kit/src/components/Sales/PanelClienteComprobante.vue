<template>
    <div class="d-flex flex-column gap-2">
        <div class="small text-uppercase fw-semibold text-secondary">Cliente</div>

        <!-- Cliente elegido -->
        <div v-if="cliente" class="border rounded p-2 d-flex align-items-center gap-2">
            <div class="avatar">{{ iniciales }}</div>
            <div class="min-w-0">
                <div class="fw-medium lh-sm">{{ cliente.full_name || cliente.name_comerc || cliente.name }}</div>
                <div class="small text-muted">
                    {{ cliente.type_document }} {{ cliente.n_document }}
                    <span v-if="cliente.address"> · {{ cliente.address }}</span>
                </div>
            </div>
            <button type="button" class="btn btn-link btn-sm ms-auto" @click="emit('limpiar')">Cambiar</button>
        </div>

        <!-- Buscador de cliente -->
        <div v-else class="position-relative">
            <div class="input-group input-group-sm">
                <input type="text" class="form-control" v-model="texto" placeholder="DNI, RUC o nombre del cliente"
                    autocomplete="off" @input="alEscribir" @keydown.enter.prevent="buscarPorDocumento" @blur="alSalir" />
                <button class="btn btn-outline-primary" type="button" title="Buscar por DNI/RUC" @click="buscarPorDocumento">
                    <i class="fas fa-search"></i>
                </button>
                <button class="btn btn-success" type="button" title="Registrar nuevo cliente" @click="emit('registrar', null)">
                    <i class="fas fa-user-plus"></i>
                </button>
            </div>
            <div v-if="sugerencias.length" class="list-group position-absolute w-100 shadow sugerencias">
                <button v-for="c in sugerencias" :key="c.id" type="button" class="list-group-item list-group-item-action"
                    @mousedown.prevent="elegir(c)">
                    <div class="d-flex justify-content-between gap-2">
                        <span>{{ c.full_name }}</span><small class="text-muted">{{ c.n_document }}</small>
                    </div>
                </button>
            </div>
            <div v-else-if="noEncontrado && texto.trim().length > 2" class="small text-danger mt-1">
                No se encontró —
                <a href="#" @click.prevent="emit('registrar', null)">regístralo ahora</a>
            </div>
            <div v-else class="small text-muted mt-1">Busca por DNI, RUC o nombre. La factura necesita un cliente con RUC.</div>
        </div>

        <!-- Dirección que sale en el comprobante (T-107): v-model = client_address_id; null = fiscal -->
        <SelectorDireccionComprobante v-model="direccionId" :cliente-id="cliente?.id" :direccion-fiscal="cliente?.address" />

        <div class="small text-uppercase fw-semibold text-secondary mt-1">Comprobante</div>
        <div class="btn-group btn-group-sm w-100" role="group">
            <button v-for="t in tipos" :key="t.codigo" type="button" class="btn" :disabled="tipoBloqueado"
                :class="tipo === t.codigo ? 'btn-success' : 'btn-outline-secondary'" @click="tipo = t.codigo">
                {{ t.nombre }}
            </button>
        </div>
        <div v-if="serieFija" class="small text-muted">
            Serie <b>{{ serieFija }}</b> · ya tiene correlativo reservado, no se puede cambiar
        </div>
        <div v-else-if="serie" class="small text-muted">
            Serie <b>{{ serie.serie }}</b> · siguiente n.º {{ serie.siguiente_correlativo }}
        </div>
        <div v-else-if="errorSerie" class="small text-danger">{{ errorSerie }}</div>
    </div>
</template>

<script setup lang="ts">
// Panel «Cliente y comprobante» de la pantalla de venta (T-106). Reutilizable en edit.vue (T-110).
// Busca clientes por documento/nombre y avisa con `seleccionar`; los efectos de elegir un cliente
// (tipo de comprobante, precios, adelantos…) los decide la pantalla que lo usa.
import { computed, onBeforeUnmount, ref } from 'vue';
import httpClient from '@/helpers/http-client';
import SelectorDireccionComprobante from '@/components/Clientes/SelectorDireccionComprobante.vue';
import type { Client } from '@/types/clients';

type TipoDoc = { codigo: string; nombre: string };

const props = defineProps<{
    cliente?: Client;
    tipos: TipoDoc[];
    serie?: { serie: string; siguiente_correlativo: number } | null;
    errorSerie?: string;
    /** Edición (T-110): la venta ya tiene correlativo; el tipo de comprobante no se puede cambiar. */
    tipoBloqueado?: boolean;
    /** Edición (T-110): serie ya asignada a la venta; sustituye a la vista previa. */
    serieFija?: string;
}>();
const emit = defineEmits<{
    (e: 'seleccionar', cliente: Client): void;
    (e: 'limpiar'): void;
    /** `datos` trae lo ya consultado en RENIEC/SUNAT para prellenar el formulario rápido; null = vacío. */
    (e: 'registrar', datos: any | null): void;
}>();

const direccionId = defineModel<number | null>('direccionId', { default: null });
const tipo = defineModel<string>('tipo', { required: true });

const texto = ref('');
const sugerencias = ref<Client[]>([]);
const noEncontrado = ref(false);
let temporizador: ReturnType<typeof setTimeout> | null = null;
onBeforeUnmount(() => { if (temporizador) clearTimeout(temporizador); });

const iniciales = computed(() => {
    const n = props.cliente?.full_name || props.cliente?.name || '?';
    return n.split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase();
});

const buscarClientes = async (q: string): Promise<Client[]> => {
    try {
        const res = await httpClient.get(`clients?search=${encodeURIComponent(q)}&take=10`);
        return res.data.clients.data as Client[];
    } catch {
        return [];
    }
};

const alEscribir = () => {
    if (temporizador) clearTimeout(temporizador);
    noEncontrado.value = false;
    const q = texto.value.trim();
    if (!q) { sugerencias.value = []; return; }
    if (/^\d{8}$/.test(q) || /^\d{11}$/.test(q)) { buscarPorDocumento(); return; }
    if (q.length >= 2) {
        temporizador = setTimeout(async () => {
            sugerencias.value = await buscarClientes(q);
            noEncontrado.value = sugerencias.value.length === 0;
        }, 300);
    }
};

const alSalir = () => setTimeout(() => { sugerencias.value = []; }, 200);

const elegir = (c: Client) => {
    texto.value = '';
    sugerencias.value = [];
    noEncontrado.value = false;
    emit('seleccionar', c);
};

// DNI/RUC exacto: el cliente si ya existe; si no, se consulta RENIEC/SUNAT y se abre el registro rápido prellenado.
const buscarPorDocumento = async () => {
    const doc = texto.value.trim();
    if (!doc) return;
    if (!/^\d{8}$/.test(doc) && !/^\d{11}$/.test(doc)) {
        sugerencias.value = await buscarClientes(doc);
        noEncontrado.value = sugerencias.value.length === 0;
        return;
    }
    const existente = (await buscarClientes(doc)).find((c) => c.n_document === doc);
    if (existente) { elegir(existente); return; }

    const tipoDoc = doc.length === 8 ? 'dni' : 'ruc';
    try {
        const { data } = await httpClient.get(`/search-document/${tipoDoc}/${doc}`);
        if (data.success === false) { emit('registrar', { type_document: tipoDoc.toUpperCase(), n_document: doc }); return; }
        const datos: any = { type_document: tipoDoc.toUpperCase(), n_document: doc };
        if (tipoDoc === 'dni') {
            datos.name = data.nombres || '';
            datos.surname = `${data.apellidoPaterno || ''} ${data.apellidoMaterno || ''}`.trim();
            datos.full_name = `${datos.name} ${datos.surname}`;
        } else {
            datos.full_name = data.razonSocial || '';
            datos.name_comerc = data.nombreComercial || '';
            datos.address = data.direccion || '';
        }
        emit('registrar', datos);
    } catch {
        emit('registrar', { type_document: tipoDoc.toUpperCase(), n_document: doc });
    }
};
</script>

<style scoped>
.avatar { width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; flex: none;
    font-weight: 600; font-size: .8rem; background: var(--bs-success-bg-subtle); color: var(--bs-success); }
.sugerencias { z-index: 1050; max-height: 240px; overflow-y: auto; top: calc(100% - 20px); }
.min-w-0 { min-width: 0; }
</style>
