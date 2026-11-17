<template>
    <!-- T-060 (CRM C5): las preferencias del viajero del cliente, en solo lectura y plegables, en la
         cabecera del cotizador. Pide sus datos solo (editar.vue solo lo monta). Sin perfil o con el
         perfil vacío no se muestra. Se editan en la ficha del cliente. Notas como texto, sin v-html. -->
    <div v-if="perfil && !perfil.vacio" class="border rounded bg-light-subtle small mb-2" style="max-width: 640px">
        <button type="button" class="btn btn-link btn-sm text-decoration-none text-dark w-100 text-start px-2 py-1 d-flex align-items-center gap-2"
            :aria-expanded="abierto" @click="abierto = !abierto">
            <i class="fas fa-fw" :class="abierto ? 'fa-chevron-down' : 'fa-chevron-right'" style="font-size:10px"></i>
            <i class="fas fa-suitcase-rolling text-primary"></i>
            <span class="fw-semibold">Preferencias del viajero</span>
            <span v-if="!abierto && perfil.tipos_viaje_nombres.length" class="text-muted text-truncate">· {{ perfil.tipos_viaje_nombres.join(', ') }}</span>
        </button>
        <div v-if="abierto" class="px-3 pb-2">
            <div v-if="perfil.tipos_viaje_nombres.length" class="mb-1">
                <span v-for="t in perfil.tipos_viaje_nombres" :key="t" class="badge bg-primary-subtle text-primary-emphasis me-1">{{ t }}</span>
            </div>
            <dl class="row mb-0">
                <template v-if="perfil.presupuesto_por_persona !== null">
                    <dt class="col-sm-4 fw-normal text-secondary">Presupuesto por persona</dt>
                    <dd class="col-sm-8 mb-1">aprox. {{ formatoMoneda(perfil.presupuesto_por_persona, perfil.moneda ?? 'PEN') }}</dd>
                </template>
                <template v-if="perfil.hotel_preferido">
                    <dt class="col-sm-4 fw-normal text-secondary">Hotel preferido</dt>
                    <dd class="col-sm-8 mb-1">{{ perfil.hotel_preferido }}</dd>
                </template>
                <template v-if="perfil.alimentacion">
                    <dt class="col-sm-4 fw-normal text-secondary">Alimentación</dt>
                    <dd class="col-sm-8 mb-1">{{ perfil.alimentacion }}</dd>
                </template>
                <template v-if="perfil.movilidad">
                    <dt class="col-sm-4 fw-normal text-secondary">Movilidad</dt>
                    <dd class="col-sm-8 mb-1">{{ perfil.movilidad }}</dd>
                </template>
                <template v-if="perfil.notas">
                    <dt class="col-sm-4 fw-normal text-secondary">Notas</dt>
                    <dd class="col-sm-8 mb-1 text-break" style="white-space: pre-line">{{ perfil.notas }}</dd>
                </template>
            </dl>
        </div>
    </div>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { formatoMoneda } from '@/helpers/dashboard';
import { fichaClienteAgenciaService, type PerfilViajero } from '@/services/admin/fichaClienteAgenciaService';

const props = defineProps<{ clienteId: number | null }>();

const perfil = ref<PerfilViajero | null>(null);
// Abierto al entrar: es lo primero que conviene leer al cotizar; se pliega con un clic.
const abierto = ref(true);

const cargar = async () => {
    perfil.value = null;
    if (!props.clienteId) return;
    const pedido = props.clienteId;
    try {
        const datos = await fichaClienteAgenciaService.perfil(pedido);
        if (pedido === props.clienteId) perfil.value = datos;
    } catch {
        // Sin permiso o sin perfil: el recuadro no se muestra (no es un error para quien cotiza).
    }
};

onMounted(cargar);
watch(() => props.clienteId, cargar);
</script>
