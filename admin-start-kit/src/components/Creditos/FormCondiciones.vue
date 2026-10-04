<template>
  <div class="row g-3">
    <!-- Monto e interés -->
    <div class="col-12 col-md-4">
      <label class="form-label mb-1 small fw-semibold text-secondary" for="fc-monto">Monto prestado</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text">S/</span>
        <input id="fc-monto" v-model="form.monto_capital" type="text" inputmode="decimal" class="form-control"
          :class="{ 'is-invalid': errores.monto_capital }" placeholder="0.00" autocomplete="off" />
      </div>
      <div v-if="errores.monto_capital" class="invalid-feedback d-block">{{ errores.monto_capital }}</div>
    </div>
    <div class="col-12 col-md-8">
      <label class="form-label mb-1 small fw-semibold text-secondary" for="fc-tasa">Interés</label>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <div class="input-group input-group-sm tasa">
          <input id="fc-tasa" v-model="form.tasa_interes" type="text" inputmode="decimal" class="form-control"
            :class="{ 'is-invalid': errores.tasa_interes }" placeholder="0" autocomplete="off" />
          <span class="input-group-text">%</span>
        </div>
        <div class="btn-group btn-group-sm" role="group" aria-label="Cómo se aplica el interés">
          <input id="fc-unidad-total" v-model="form.unidad_tasa" type="radio" class="btn-check" value="total" />
          <label class="btn btn-outline-primary" for="fc-unidad-total">Sobre el total</label>
          <input id="fc-unidad-mensual" v-model="form.unidad_tasa" type="radio" class="btn-check" value="mensual" />
          <label class="btn btn-outline-primary" for="fc-unidad-mensual">Mensual</label>
        </div>
      </div>
      <div v-if="errores.tasa_interes" class="invalid-feedback d-block">{{ errores.tasa_interes }}</div>
      <small class="text-muted">
        {{ form.unidad_tasa === 'total'
          ? `El ${form.tasa_interes || '…'}% se cobra una sola vez sobre el monto prestado.`
          : `El ${form.tasa_interes || '…'}% se cobra por cada mes del plazo.` }}
        <span v-if="form.unidad_tasa === 'total' && tasaMensual" class="d-block fw-semibold text-body">Equivale a {{ tasaMensual }}% mensual.</span>
      </small>
    </div>

    <!-- Forma y número de pagos, fechas -->
    <div class="col-6 col-md-3">
      <label class="form-label mb-1 small fw-semibold text-secondary" for="fc-forma">Forma de pago</label>
      <select id="fc-forma" v-model="form.forma_pago" class="form-select form-select-sm">
        <option v-for="f in FORMAS_PAGO" :key="f.valor" :value="f.valor">{{ f.etiqueta }}</option>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label mb-1 small fw-semibold text-secondary" for="fc-n">N.º de pagos</label>
      <input id="fc-n" v-model="form.numero_cuotas" type="text" inputmode="numeric" class="form-control form-control-sm"
        :class="{ 'is-invalid': errores.numero_cuotas }" placeholder="0" autocomplete="off" />
      <div v-if="errores.numero_cuotas" class="invalid-feedback d-block">{{ errores.numero_cuotas }}</div>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label mb-1 small fw-semibold text-secondary" for="fc-desembolso">
        {{ desembolsoEditable ? '¿Cuándo entregó el dinero?' : 'Entrega del dinero' }}<span v-if="desembolsoEditable" class="text-danger"> *</span>
      </label>
      <CampoFecha v-if="desembolsoEditable" id="fc-desembolso" v-model="form.fecha_desembolso" :max="fechaMaxima" etiqueta="la entrega del dinero"
        :invalido="!!errores.fecha_desembolso" />
      <input v-else id="fc-desembolso" :value="formatoFecha(form.fecha_desembolso)" type="text" class="form-control form-control-sm" readonly />
      <small v-if="!desembolsoEditable" class="text-muted">Hoy, al activar.</small>
      <small v-else-if="ayudaDesembolso" class="text-muted">{{ ayudaDesembolso }}</small>
      <small v-else-if="!form.fecha_desembolso" class="text-muted">Obligatoria; debe ser anterior a hoy.</small>
      <div v-if="errores.fecha_desembolso" class="invalid-feedback d-block">{{ errores.fecha_desembolso }}</div>
    </div>
    <div class="col-6 col-md-4">
      <label class="form-label mb-1 small fw-semibold text-secondary d-flex align-items-center gap-1" for="fc-primer">
        Primer pago
        <span v-if="!form.fecha_primer_vencimiento && primerSugerido" class="badge bg-info-subtle text-info-emphasis fw-normal">automático</span>
      </label>
      <!-- La fecha automática se MUESTRA pero no se guarda: guardarla la volvería el ancla del
           cronograma y dejaría de recalcularse al cambiar la forma de pago o la entrega. -->
      <CampoFecha id="fc-primer" :model-value="form.fecha_primer_vencimiento || primerSugerido || ''" etiqueta="el primer pago"
        :min="form.fecha_desembolso || undefined" :invalido="!!errores.fecha_primer_vencimiento"
        @update:model-value="cambiarPrimerPago" />
      <small class="text-muted d-block">
        <a v-if="form.fecha_primer_vencimiento" href="#" @click.prevent="form.fecha_primer_vencimiento = ''">Usar automático</a>
        <template v-else-if="primerSugerido">Elige otra fecha en el calendario para cambiarla.</template>
      </small>
      <small v-if="ajustePrimerPago" class="text-warning-emphasis d-block"><i class="fas fa-info-circle me-1"></i>{{ ajustePrimerPago }}</small>
      <div v-if="errores.fecha_primer_vencimiento" class="invalid-feedback d-block">{{ errores.fecha_primer_vencimiento }}</div>
    </div>
    <div v-if="metodosPago.length > 1" class="col-12 col-md-4">
      <label class="form-label mb-1 small fw-semibold text-secondary" for="fc-metodo">Método de entrega</label>
      <select id="fc-metodo" v-model="form.payment_method_id" class="form-select form-select-sm">
        <option v-for="m in metodosPago" :key="m.id" :value="m.id">{{ m.name }}</option>
      </select>
    </div>
    <div class="col-12">
      <div class="form-check mb-0">
        <input id="fc-domingos" v-model="noCobrarDomingos" type="checkbox" class="form-check-input" />
        <label class="form-check-label small" for="fc-domingos">{{ domingos.etiqueta }}</label>
        <small v-if="domingos.ayuda" class="text-muted d-block">{{ domingos.ayuda }}</small>
      </div>
    </div>

    <!-- Opciones avanzadas (plegado, 00 §4 / mockup 1) -->
    <div class="col-12">
      <div class="border rounded">
        <button type="button" class="btn btn-sm w-100 d-flex justify-content-between align-items-center text-start px-3 py-2"
          :aria-expanded="avanzadas" @click="avanzadas = !avanzadas">
          <span>
            <span class="fw-semibold small d-block">Opciones avanzadas</span>
            <small class="text-muted">{{ resumenAvanzado(form) }}</small>
          </span>
          <i class="fas" :class="avanzadas ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
        </button>
        <div v-show="avanzadas" class="p-3 border-top">
          <div class="row g-3">
            <div class="col-12">
              <div class="form-check form-switch mb-0">
                <input id="fc-cobra-mora" v-model="form.cobra_mora" type="checkbox" role="switch" class="form-check-input" />
                <label class="form-check-label small fw-semibold" for="fc-cobra-mora">Cobrar mora por atraso</label>
              </div>
              <small v-if="!form.cobra_mora" class="text-muted">Este crédito no cobrará interés moratorio aunque el cliente se atrase.</small>
            </div>
            <div v-if="form.cobra_mora" class="col-6 col-md-4">
              <label class="form-label mb-1 small fw-semibold text-secondary" for="fc-gracia">Días de gracia</label>
              <input id="fc-gracia" v-model="form.dias_gracia" type="text" inputmode="numeric" class="form-control form-control-sm"
                :class="{ 'is-invalid': errores.dias_gracia }" />
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label mb-1 small fw-semibold text-secondary" for="fc-minimo">Interés mínimo al liquidar (%)</label>
              <input id="fc-minimo" v-model="form.tasa_interes_minimo" type="text" inputmode="decimal" class="form-control form-control-sm"
                :class="{ 'is-invalid': errores.tasa_interes_minimo }" />
            </div>
            <div v-if="form.cobra_mora" class="col-12 col-md-4">
              <label class="form-label mb-1 small fw-semibold text-secondary" for="fc-tope">Tope de mora</label>
              <div class="input-group input-group-sm">
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
              <span class="form-label mb-1 small fw-semibold text-secondary d-block">Días sin cobro</span>
              <div class="d-flex flex-wrap gap-1">
                <template v-for="d in DIAS" :key="d.iso">
                  <input :id="`fc-dia-${d.iso}`" v-model="form.dias_no_laborables" type="checkbox" class="btn-check" :value="d.iso" />
                  <label class="btn btn-sm btn-outline-secondary" :for="`fc-dia-${d.iso}`">{{ d.corto }}</label>
                </template>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-check">
                <input id="fc-feriados" v-model="form.saltar_feriados" type="checkbox" class="form-check-input" />
                <label class="form-check-label small" for="fc-feriados">No cobrar feriados</label>
              </div>
              <div v-if="form.cobra_mora" class="form-check">
                <input id="fc-mora-dias" v-model="form.mora_cuenta_no_laborables" type="checkbox" class="form-check-input" />
                <label class="form-check-label small" for="fc-mora-dias">La mora cuenta también días sin cobro</label>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label mb-1 small fw-semibold text-secondary" for="fc-regla">Si un pago cae en día sin cobro</label>
              <select id="fc-regla" v-model="form.regla_no_laborable" class="form-select form-select-sm">
                <option value="siguiente">Pasar al siguiente día hábil</option>
                <option value="anterior">Adelantar al día hábil anterior</option>
                <option value="mantener">Mantener la fecha</option>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos (mockup 1): condiciones del crédito. Reutilizado por Nuevo crédito,
// Registrar existente, Corregir y Renovar. Solo edita el formulario; montos y fechas
// resultantes los calcula el backend (preview). Estilo de formulario de Agencia/Umbo
// (etiquetas small, controles -sm).
import { computed, ref, watch } from 'vue'
import CampoFecha from '@/components/CampoFecha.vue'
import { DOMINGO, FORMAS_PAGO, resumenAvanzado, type FormCredito } from '@/helpers/creditos/formulario'
import { textoAjuste, textoDomingos } from '@/helpers/creditos/cronograma'
import { formatoFecha } from '@/helpers/creditos/formato'
import type { CuotaPrevia, MetodoPago } from '@/types/creditos'

const props = withDefaults(defineProps<{
  errores?: Record<string, string>
  metodosPago?: MetodoPago[]
  primerSugerido?: string | null
  desembolsoEditable?: boolean
  fechaMaxima?: string
  /** Nota bajo la fecha de entrega cuando es editable (Corregir: solo unos días hacia atrás). */
  ayudaDesembolso?: string
  /** Primera cuota del preview: explica si su fecha se movió por domingo o feriado. */
  primeraCuota?: CuotaPrevia | null
  /** "Sobre el total" expresado en % mensual simple (lo calcula el backend). */
  tasaMensual?: string | null
}>(), { errores: () => ({}), metodosPago: () => [], primerSugerido: null, desembolsoEditable: false, fechaMaxima: undefined, ayudaDesembolso: undefined, primeraCuota: null, tasaMensual: null })

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
const domingos = computed(() => textoDomingos(form.value.forma_pago, form.value.regla_no_laborable))
const ajustePrimerPago = computed(() => (props.primeraCuota ? textoAjuste(props.primeraCuota) : null))

/** Elegir la misma fecha automática no la fija: solo una fecha distinta se vuelve manual. */
function cambiarPrimerPago(valor: string) {
  if (!form.value.fecha_primer_vencimiento && valor === props.primerSugerido) return
  form.value.fecha_primer_vencimiento = valor
}

// Si el backend rechaza un campo de opciones avanzadas, se despliega la sección para que se vea.
const CAMPOS_AVANZADOS = ['dias_gracia', 'tasa_interes_minimo', 'tope_mora_tipo', 'tope_mora_valor', 'dias_no_laborables', 'regla_no_laborable']
watch(() => props.errores, (e) => {
  if (CAMPOS_AVANZADOS.some((c) => e[c])) avanzadas.value = true
})
</script>

<style scoped>
.tasa {
  width: 7rem;
  flex-shrink: 0;
}
.valor-tope {
  max-width: 5rem;
}
</style>
