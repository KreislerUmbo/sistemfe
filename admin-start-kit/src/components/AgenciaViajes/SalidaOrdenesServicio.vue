<template>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between">
            <span class="fw-semibold small">Órdenes de servicio</span>
            <button type="button" class="btn btn-outline-primary btn-sm" :disabled="cargando" @click="cargar">
                <span v-if="cargando" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="fas fa-file-lines me-1"></i>{{ ordenes === null ? 'Preparar órdenes' : 'Actualizar' }}
            </button>
        </div>
        <div v-if="ordenes !== null" class="card-body">
            <div v-if="ordenes.length === 0" class="text-muted small fst-italic">
                Ningún servicio ni vehículo tiene proveedor asignado todavía.
            </div>
            <div v-for="o in ordenes" :key="o.proveedor_id" class="border rounded p-2 mb-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
                    <span class="fw-semibold small">{{ o.proveedor }}</span>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="copiar(o)">
                            <i class="far fa-copy me-1"></i>Copiar
                        </button>
                        <a v-if="o.whatsapp_url" :href="o.whatsapp_url" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm">
                            <i class="fab fa-whatsapp me-1"></i>WhatsApp
                        </a>
                        <span v-else class="small text-muted align-self-center" title="El proveedor no tiene WhatsApp, celular ni teléfono cargado">sin teléfono</span>
                        <button type="button" class="btn btn-outline-danger btn-sm" @click="pdf(o)">
                            <i class="far fa-file-pdf me-1"></i>PDF
                        </button>
                    </div>
                </div>
                <pre class="small bg-light rounded p-2 mb-0" style="white-space:pre-wrap">{{ o.mensaje }}</pre>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
// Fila 13, fase 2c (plan-tours-venta-simple-costo-real.md §3.13, D14): la orden
// de servicio de cada proveedor de la salida. Se arma en el momento (siempre
// al día con cancelaciones y cambios), con el texto para WhatsApp y el PDF.
import { ref } from 'vue';
import { salidaOperativaService, type OrdenServicio } from '@/services/admin/salidaOperativaService';
import { useToast } from '@/composables/useToast';

const props = defineProps<{ salidaId: number }>();

const toast = useToast();
const ordenes = ref<OrdenServicio[] | null>(null);
const cargando = ref(false);

const cargar = async () => {
    cargando.value = true;
    try {
        ordenes.value = (await salidaOperativaService.ordenes(props.salidaId)).ordenes;
    } catch (error: any) {
        toast.error(error.response?.data?.message ?? 'No se pudieron preparar las órdenes de servicio');
    } finally {
        cargando.value = false;
    }
};

const copiar = async (o: OrdenServicio) => {
    try {
        await navigator.clipboard.writeText(o.mensaje);
        toast.success('Orden copiada');
    } catch {
        toast.error('No se pudo copiar: selecciona el texto y cópialo a mano');
    }
};

const pdf = async (o: OrdenServicio) => {
    try {
        await salidaOperativaService.abrirOrdenPdf(props.salidaId, o.proveedor_id);
    } catch {
        toast.error('No se pudo generar el PDF');
    }
};
</script>
