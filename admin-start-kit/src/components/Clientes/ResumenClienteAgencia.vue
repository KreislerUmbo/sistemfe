<template>
    <!-- T-060 (CRM C5, solo Agencia): resumen del cliente arriba de la ficha. Las cifras respetan lo
         que el usuario puede ver (sus cotizaciones y reservas, o todas con «ver todas»). -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div v-if="cargando" class="text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>Cargando resumen…</div>
            <div v-else-if="errorCarga" class="text-danger small">{{ errorCarga }}</div>
            <template v-else-if="resumen">
                <div class="row g-2 text-center">
                    <div class="col-6 col-md-3">
                        <div class="small text-secondary">Cotizaciones</div>
                        <div class="fw-bold fs-5">{{ resumen.cotizaciones }}</div>
                        <div class="small">
                            <span class="text-success">{{ resumen.ganadas }} ganadas</span> ·
                            <span class="text-danger">{{ resumen.perdidas }} perdidas</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-secondary">Viajes realizados</div>
                        <div class="fw-bold fs-5">{{ resumen.viajes_realizados }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-secondary">Total comprado</div>
                        <div v-if="!resumen.total_comprado.length" class="fw-bold fs-5">—</div>
                        <div v-for="t in resumen.total_comprado" :key="t.moneda" class="fw-bold">{{ formatoMoneda(t.monto, t.moneda) }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-secondary">Último viaje</div>
                        <router-link v-if="resumen.ultimo_viaje" class="fw-semibold small d-block"
                            :to="{ name: 'agencia.reservas.detalle', params: { id: resumen.ultimo_viaje.reserva_id } }">
                            {{ resumen.ultimo_viaje.codigo }}
                        </router-link>
                        <div v-if="resumen.ultimo_viaje" class="small text-muted">
                            {{ formatFecha(resumen.ultimo_viaje.desde) }}<template v-if="resumen.ultimo_viaje.hasta"> – {{ formatFecha(resumen.ultimo_viaje.hasta) }}</template>
                        </div>
                        <div v-else class="fw-bold fs-5">—</div>
                    </div>
                </div>
                <div class="border-top mt-2 pt-2 small text-muted">
                    <i class="fas fa-user-clock me-1"></i>Atendido por última vez por
                    <span v-if="atendidoPor" class="fw-semibold text-dark">{{ atendidoPor.nombre }}</span>
                    <template v-if="atendidoPor"> ({{ formatFechaHora(atendidoPor.fecha) }})</template>
                    <span v-else>nadie todavía</span>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { formatFecha, formatFechaHora } from '@/helpers/fecha';
import { formatoMoneda } from '@/helpers/dashboard';
import { fichaClienteAgenciaService, type AtendidoPor, type ResumenClienteAgencia } from '@/services/admin/fichaClienteAgenciaService';

const props = defineProps<{ clienteId: number }>();

const cargando = ref(true);
const errorCarga = ref<string | null>(null);
const resumen = ref<ResumenClienteAgencia | null>(null);
const atendidoPor = ref<AtendidoPor | null>(null);

const recargar = async () => {
    errorCarga.value = null;
    try {
        const datos = await fichaClienteAgenciaService.resumen(props.clienteId);
        resumen.value = datos.resumen;
        atendidoPor.value = datos.atendido_por;
    } catch (e: any) {
        errorCarga.value = e?.response?.data?.message ?? 'No se pudo cargar el resumen.';
    } finally {
        cargando.value = false;
    }
};

onMounted(recargar);
watch(() => props.clienteId, () => { cargando.value = true; recargar(); });

defineExpose({ recargar });
</script>
