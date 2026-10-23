<template>
    <!-- Moneda del comprobante (2026-09-24, pedido del usuario: "tengo mi
         cotización en dólares, pero el cliente pide que le facture en soles").
         Siempre visible, también con bloqueo tributario. -->
    <div class="mb-2">
        <label class="form-label small fw-semibold text-secondary">
            <i class="fas fa-money-bill-wave me-1"></i>Moneda del comprobante
        </label>
        <select class="form-select form-select-sm" v-model="opciones.moneda">
            <option value="PEN">Soles (PEN)</option>
            <option value="USD">Dólares (USD)</option>
        </select>
        <small class="text-muted d-block mt-1">Cotización en {{ monedaCotizacion }}.</small>
    </div>
    <div v-if="opciones.requiereOverride" class="row g-2 mb-2">
        <div class="col-6">
            <label class="form-label small fw-semibold text-secondary">
                Tipo de cambio ({{ monedaCotizacion }} → {{ opciones.moneda }})
            </label>
            <input type="number" step="0.0001" min="0.0001" class="form-control form-control-sm"
                v-model.number="opciones.tipoCambio" placeholder="Ej: 3.75">
            <small v-if="tipoCambioSugerido" class="text-muted">
                Sugerido SUNAT del día: {{ tipoCambioSugerido }}
            </small>
        </div>
        <div class="col-6">
            <label class="form-label small fw-semibold text-secondary">Motivo del cambio de moneda</label>
            <input type="text" class="form-control form-control-sm" v-model="opciones.motivo"
                placeholder="Ej: cliente pidió el comprobante en soles">
        </div>
    </div>

    <!-- Sucursal (2026-09-24): define la serie según la moneda. Solo con
         permiso can_switch_branch, igual que sale/register.vue. -->
    <div v-if="opciones.puedeCambiarSucursal" class="mb-2">
        <label class="form-label small fw-semibold text-secondary">
            <i class="fas fa-store me-1"></i>Sucursal (define la serie según moneda)
        </label>
        <select class="form-select form-select-sm" v-model.number="opciones.branchId">
            <option v-for="b in opciones.sucursales" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
    </div>

    <!-- Serie visible (2026-09-24, pedido del usuario): solo lectura, no
         reserva ningún correlativo. -->
    <div v-if="serieResuelta" class="mb-2 small">
        <span class="text-muted">Serie a emitir:</span>
        <span class="badge bg-light text-dark border ms-1">{{ serieResuelta }}</span>
    </div>
</template>

<script setup lang="ts">
// Moneda, sucursal y serie del comprobante, comunes a "Facturar" y
// "Facturación especial" (antes copiados en los dos modales). El estado
// vive en useOpcionesFacturacion(); este componente solo lo muestra.
import type { OpcionesFacturacion } from '@/composables/agencia-viajes/useOpcionesFacturacion';

defineProps<{
    opciones: OpcionesFacturacion;
    monedaCotizacion: string;
    tipoCambioSugerido?: number | null;
    serieResuelta?: string | null;
}>();
</script>
