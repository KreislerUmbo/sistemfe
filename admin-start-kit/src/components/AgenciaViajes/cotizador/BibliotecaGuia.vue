<template>
    <GuiaBuscador v-model="guiaSeleccionadaIdCotizador" class="mb-2" placeholder="Buscar guía…"
        @elegido="cargarTarifasGuiaCotizador" />
    <div v-if="guiaSeleccionadaIdCotizador" class="d-flex flex-column gap-1 mb-2">
        <div v-for="t in tarifasGuiaCotizador" :key="t.id" class="border rounded p-2 small lib-item"
            :class="{ 'border-primary bg-light': guiaTarifaSeleccionadaCotizador?.id === t.id }"
            style="cursor:pointer"
            @click="guiaTarifaSeleccionadaCotizador = t">
            <div class="d-flex justify-content-between">
                <span><i class="fas fa-user-tie me-1 text-primary"></i>{{ t.destino?.nombre }}</span>
                <span class="text-muted">{{ t.moneda }} {{ Number(t.costo_diario).toFixed(0) }}/día</span>
            </div>
            <div class="text-muted" style="font-size:11px">{{ t.modalidad === 'dia_local' ? 'Día local' : 'Grupo multidía' }}</div>
        </div>
        <div v-if="tarifasGuiaCotizador.length === 0" class="text-muted small text-center py-2">Este guía no tiene tarifas cargadas.</div>
    </div>
    <div v-if="guiaTarifaSeleccionadaCotizador" class="mb-2">
        <label class="form-label mb-1 small text-secondary">Cantidad de días</label>
        <input type="number" min="1" class="form-control form-control-sm" v-model.number="cantidadDiasGuia">
    </div>
    <div v-if="guiaTarifaSeleccionadaCotizador" class="row g-2 mb-2">
        <div class="col-6">
            <label class="form-label mb-1 small text-secondary">Tratamiento tributario</label>
            <select class="form-select form-select-sm" v-model="tipAfeIgvGuia">
                <option value="10">Gravado</option>
                <option value="20">Exonerado</option>
                <option value="30">Inafecto</option>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label mb-1 small text-secondary">Destino</label>
            <select class="form-select form-select-sm" v-model="destinoTributarioGuia">
                <option value="amazonia">Amazonía</option>
                <option value="nacional">Nacional</option>
                <option value="extranjero">Extranjero</option>
            </select>
        </div>
    </div>
    <button class="btn btn-primary btn-sm w-100" @click="agregarItemGuiaCotizador" :disabled="!guiaTarifaSeleccionadaCotizador || agregandoGuia">
        <span v-if="agregandoGuia" class="spinner-border spinner-border-sm me-1"></span>Agregar guía
    </button>
</template>

<script setup lang="ts">
/*
 * Guía como ítem del drawer: buscar guía, elegir su tarifa, días y tratamiento tributario. El
 * v-else-if="modo === 'guia'" quedó en el tag. Todo llega por inject desde cotizador/editar.vue
 * (T-105).
 */
import GuiaBuscador from '@/components/AgenciaViajes/GuiaBuscador.vue';
import { CLAVE_GUIA, inyectar } from '@/composables/agencia-viajes/cotizador/claves';

const {
    agregandoGuia, agregarItemGuiaCotizador, cantidadDiasGuia, cargarTarifasGuiaCotizador,
    destinoTributarioGuia, guiaSeleccionadaIdCotizador, guiaTarifaSeleccionadaCotizador,
    tarifasGuiaCotizador, tipAfeIgvGuia,
} = inyectar(CLAVE_GUIA);
</script>

<style scoped>
.lib-item:hover { background: #eef2ff; border-color: #6366f1 !important; }
</style>
