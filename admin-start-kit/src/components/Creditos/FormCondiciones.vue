<template>
  <div class="d-flex flex-column gap-3">
    <!-- Monto -->
    <div>
      <label class="form-label fw-semibold small text-uppercase text-muted mb-1" for="fc-monto">Monto prestado</label>
      <div class="input-group input-group-lg">
        <span class="input-group-text fw-bold">S/</span>
        <input id="fc-monto" v-model="form.monto_capital" type="text" inputmode="decimal" class="form-control fw-semibold"
          :class="{ 'is-invalid': errores.monto_capital }" placeholder="0.00" autocomplete="off" />
      </div>
      <div v-if="errores.monto_capital" class="invalid-feedback d-block">{{ errores.monto_capital }}</div>
    </div>

    <!-- Interés + total/mensual -->
    <div>
      <label class="form-label fw-semibold small text-uppercase text-muted mb-1" for="fc-tasa">Interés</label>
      <div class="d-flex flex-wrap gap-2">
        <div class="input-group input-group-lg tasa">
          <input id="fc-tasa" v-model="form.tasa_interes" type="text" inputmode="decimal" class="form-control fw-semibold"
            :class="{ 'is-invalid': errores.tasa_interes }" placeholder="0" autocomplete="off" />
          <span class="input-group-text fw-bold">%</span>
        </div>
        <div class="btn-group unidad-tasa" role="group" aria-label="Cómo se aplica el interés">
          <input id="fc-unidad-total" v-model="form.unidad_tasa" type="radio" class="btn-check" value="total" />
          <label class="btn btn-outline-primary d-flex align-items-center justify-content-center text-nowrap" for="fc-unidad-total">Sobre el total</label>
          <input id="fc-unidad-mensual" v-model="form.unidad_tasa" type="radio" class="btn-check" value="mensual" />
          <label class="btn btn-outline-primary d-flex align-items-center justify-content-center text-nowrap" for="fc-unidad-mensual">Mensual</label>
        </div>
      </div>
      <div v-if="errores.tasa_interes" class="invalid-feedback d-block">{{ errores.tasa_interes }}</div>
      <small class="text-muted">
        {{ form.unidad_tasa === 'total'
          ? `El ${form.tasa_interes || '…'}% se cobra una sola vez sobre el monto prestado.`
          : `El ${form.tasa_interes || '…'}% se cobra por cada mes del plazo.` }}
      </small>
    </div>

    <!-- Forma y número de pagos, fechas -->
    <div class="row g-3">
      <div class="col-6">
        <label class="form-label fw-semibold small text-uppercase text-muted mb-1" for="fc-forma">Forma de pago</label>
        <select id="fc-forma" v-model="form.forma_pago" class="form-select form-select-lg">
          <option v-for="f in FORMAS_PAGO" :key="f.valor" :value="f.valor">{{ f.etiqueta }}</option>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold small text-uppercase text-muted mb-1" for="fc-n">N.º de pagos</label>
        <input id="fc-n" v-model="form.numero_cuotas" type="text" inputmode="numeric" class="form-control form-control-lg fw-semibold"
          :class="{ 'is-invalid': errores.numero_cuotas }" placeholder="0" autocomplete="off" />
        <div v-if="errores.numero_cuotas" class="invalid-feedback d-block">{{ errores.numero_cuotas }}</div>
      </div>
      <div class="col-12 col-sm-6">
        <label class="form-label fw-semibold small text-uppercase text-muted mb-1" for="fc-desembolso">Entrega del dinero</label>
        <input v-if="desembolsoEditable" id="fc-desembolso" v-model="form.fecha_desembolso" type="date" class="form-control form-control-lg"
          :max="fechaMaxima" :class="{ 'is-invalid': errores.fecha_desembolso }" />
        <input v-else id="fc-desembolso" :value="formatoFecha(form.fecha_desembolso)" type="text" class="form-control form-control-lg" readonly />
        <small v-if="!desembolsoEditable" class="text-muted">Hoy: el dinero se entrega al activar.</small>
        <div v-if="errores.fecha_desembolso" class="invalid-feedback d-block">{{ errores.fecha_desembolso }}</div>
      </div>
      <div class="col-12 col-sm-6">
        <label class="form-label fw-semibold small text-uppercase text-muted mb-1" for="fc-primer">Primer pago</label>
        <input id="fc-primer" v-model="form.fecha_primer_vencimiento" type="date" class="form-control form-control-lg"
          :class="{ 'is-invalid': errores.fecha_primer_vencimiento }" />
        <small class="text-muted">
          <template v-if="!form.fecha_primer_vencimiento">Automático{{ primerSugerido ? `: ${formatoFecha(primerSugerido)}` : '' }}</template>
          <a v-else href="#" @click.prevent="form.fecha_primer_vencimiento = ''">Usar automático</a>
        </small>
        <div v-if="errores.fecha_primer_vencimiento" class="invalid-feedback d-block">{{ errores.fecha_primer_vencimiento }}</div>
      </div>
      <div v-if="metodosPago.length > 1" class="col-12">
        <label class="form-label fw-semibold small text-uppercase text-muted mb-1" for="fc-metodo">Método de entrega</label>
        <select id="fc-metodo" v-model="form.payment_method_id" class="form-select form-select-lg">
          <option v-for="m in metodosPago" :key="m.id" :value="m.id">{{ m.name }}</option>
        </select>
      </div>
    </div>

    <div class="form-check ps-0 d-flex align-items-center gap-2 fila-toque">
      <input id="fc-domingos" v-model="noCobrarDomingos" type="checkbox" class="form-check-input m-0" />
      <label class="form-check-label fw-semibold" for="fc-domingos">No cobrar domingos</label>
    </div>

    <!-- Opciones avanzadas (plegado, 00 §4 / mockup 1) -->
    <div class="border rounded-3">
      <button type="button" class="btn w-100 d-flex justify-content-between align-items-center text-start py-2 px-3 fila-toque"
        :aria-expanded="avanzadas" @click="avanzadas = !avanzadas">
        <span>
          <span class="fw-semibold d-block">Opciones avanzadas</span>
          <small class="text-muted">{{ resumenAvanzado(form) }}</small>
        </span>
        <i class="fas" :class="avanzadas ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
      </button>
      <div v-show="avanzadas" class="p-3 border-top">
        <div class="row g-3">
          <div class="col-6 col-md-4">
            <label class="form-label small" for="fc-gracia">Días de gracia</label>
            <input id="fc-gracia" v-model="form.dias_gracia" type="text" inputmode="numeric" class="form-control"
              :class="{ 'is-invalid': errores.dias_gracia }" />
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label small" for="fc-minimo">Interés mínimo al liquidar (%)</label>
            <input id="fc-minimo" v-model="form.tasa_interes_minimo" type="text" inputmode="decimal" class="form-control"
              :class="{ 'is-invalid': errores.tasa_interes_minimo }" />
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label small" for="fc-tope">Tope de mora</label>
            <div class="input-group">
              <select id="fc-tope" v-model="form.tope_mora_tipo" class="form-select">
                <option value="porcentaje_cuota">% de la cuota</option>
                <option value="porcentaje_capital">% del capital</option>
                <option value="dias_maximos">Días máximos</option>
                <option value="sin_tope">Sin tope</option>
              </select>
              <input v-if="form.tope_mora_tipo !== 'sin_tope'" v-model="form.tope_mora_valor" type="text" inputmode="numeric"
                class="form-control valor-tope" aria-label="Valor del tope" />
            </div>
          </div>
          <div class="col-12">
            <span class="form-label small d-block">Días sin cobro</span>
            <div class="d-flex flex-wrap gap-2">
              <template v-for="d in DIAS" :key="d.iso">
                <input :id="`fc-dia-${d.iso}`" v-model="form.dias_no_laborables" type="checkbox" class="btn-check" :value="d.iso" />
                <label class="btn btn-sm btn-outline-secondary" :for="`fc-dia-${d.iso}`">{{ d.corto }}</label>
              </template>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="form-check">
              <input id="fc-feriados" v-model="form.saltar_feriados" type="checkbox" class="form-check-input" />
              <label class="form-check-label" for="fc-feriados">No cobrar feriados</label>
            </div>
            <div class="form-check">
              <input id="fc-mora-dias" v-model="form.mora_cuenta_no_laborables" type="checkbox" class="form-check-input" />
              <label class="form-check-label" for="fc-mora-dias">La mora cuenta también días sin cobro</label>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small" for="fc-regla">Si un pago cae en día sin cobro</label>
            <select id="fc-regla" v-model="form.regla_no_laborable" class="form-select">
              <option value="siguiente">Pasar al siguiente día hábil</option>
              <option value="anterior">Adelantar al día hábil anterior</option>
              <option value="mantener">Mantener la fecha</option>
            </select>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (mockup 1): condiciones del crédito. Reutilizado por Nuevo crédito,
// Registrar existente, Corregir y Renovar. Solo edita el formulario; montos y fechas
// resultantes los calcula el backend (preview).
import { computed, ref, watch } from 'vue'
import { DOMINGO, FORMAS_PAGO, resumenAvanzado, type FormCredito } from '@/helpers/creditos/formulario'
import { formatoFecha } from '@/helpers/creditos/formato'
import type { MetodoPago } from '@/types/creditos'

const props = withDefaults(defineProps<{
  errores?: Record<string, string>
  metodosPago?: MetodoPago[]
  primerSugerido?: string | null
  desembolsoEditable?: boolean
  fechaMaxima?: string
}>(), { errores: () => ({}), metodosPago: () => [], primerSugerido: null, desembolsoEditable: false, fechaMaxima: undefined })

const form = defineModel<FormCredito>({ required: true })
const avanzadas = ref(false)

const DIAS = [
  { iso: 1, corto: 'Lun' }, { iso: 2, corto: 'Mar' }, { iso: 3, corto: 'Mié' }, { iso: 4, corto: 'Jue' },
  { iso: 5, corto: 'Vie' }, { iso: 6, corto: 'Sáb' }, { iso: 7, corto: 'Dom' },
]

const noCobrarDomingos = computed({
  get: () => form.value.dias_no_laborables.includes(DOMINGO),
  set: (activo: boolean) => {
    const sinDomingo = form.value.dias_no_laborables.filter((d) => d !== DOMINGO)
    form.value.dias_no_laborables = activo ? [...sinDomingo, DOMINGO] : sinDomingo
  },
})

const errores = computed(() => props.errores)

// Si el backend rechaza un campo de opciones avanzadas, se despliega la sección para que se vea.
const CAMPOS_AVANZADOS = ['dias_gracia', 'tasa_interes_minimo', 'tope_mora_tipo', 'tope_mora_valor', 'dias_no_laborables', 'regla_no_laborable']
watch(() => props.errores, (e) => {
  if (CAMPOS_AVANZADOS.some((c) => e[c])) avanzadas.value = true
})
</script>

<style scoped>
.tasa {
  width: 7.5rem;
  flex-shrink: 0;
}
.unidad-tasa {
  flex: 1 1 13rem;
  min-height: 48px;
}
.valor-tope {
  max-width: 5.5rem;
}
.fila-toque {
  min-height: 44px;
}
.form-check-input[type='checkbox'] {
  width: 1.35rem;
  height: 1.35rem;
}
</style>
