<template>
  <DialogoBase v-model="abierto" titulo="Reprogramar fechas" texto-confirmar="Reprogramar" tamano="lg"
    :procesando="procesando" :deshabilitado="!preview || !motivo.trim()" :error="error" @confirmar="confirmar">
    <p class="small text-muted">Cambia solo las fechas de cuotas pendientes; los montos no cambian.</p>

    <div class="btn-group w-100 mb-3" role="group" aria-label="Modo">
      <input id="rp-desplazar" v-model="modo" type="radio" class="btn-check" value="desplazar" />
      <label class="btn btn-outline-primary" for="rp-desplazar">Mover N días</label>
      <input id="rp-editar" v-model="modo" type="radio" class="btn-check" value="editar" />
      <label class="btn btn-outline-primary" for="rp-editar">Fecha por cuota</label>
    </div>

    <div v-if="modo === 'desplazar'" class="row g-2 mb-3">
      <div class="col-7">
        <label class="form-label mb-1 small fw-semibold text-secondary" for="rp-desde">Desde la cuota</label>
        <select id="rp-desde" v-model.number="desdeCuota" class="form-select form-select-sm">
          <option v-for="c in pendientes" :key="c.id" :value="c.numero_cuota">#{{ c.numero_cuota }} · {{ formatoFecha(c.fecha_vencimiento) }}</option>
        </select>
      </div>
      <div class="col-5">
        <label class="form-label mb-1 small fw-semibold text-secondary" for="rp-dias">Días</label>
        <input id="rp-dias" v-model="dias" type="text" inputmode="numeric" class="form-control form-control-sm" />
      </div>
    </div>
    <div v-else class="mb-3 lista-fechas">
      <div v-for="c in pendientes" :key="c.id" class="d-flex align-items-center gap-2 mb-2">
        <span class="text-muted numero">#{{ c.numero_cuota }}</span>
        <span class="small text-muted flex-grow-1">{{ formatoFecha(c.fecha_vencimiento) }} →</span>
        <input v-model="fechas[c.numero_cuota]" type="date" class="form-control form-control-sm fecha" :aria-label="`Nueva fecha cuota ${c.numero_cuota}`" />
      </div>
    </div>

    <div v-if="cargandoPreview" class="text-muted small mb-2"><span class="spinner-border spinner-border-sm me-1"></span>Calculando…</div>
    <div v-else-if="errorPreview" class="alert alert-warning py-2 small">{{ errorPreview }}</div>
    <template v-else-if="preview">
      <div class="table-responsive mb-3">
        <table class="table table-sm mb-0 cifra">
          <thead class="table-light"><tr><th>Cuota</th><th>Antes</th><th>Nueva</th><th class="text-end">Mora congelada</th></tr></thead>
          <tbody>
            <tr v-for="c in preview.cambios" :key="c.numero_cuota">
              <td>#{{ c.numero_cuota }}</td>
              <td>{{ formatoFecha(c.fecha_anterior) }}</td>
              <td>
                {{ formatoFecha(c.fecha_nueva) }}
                <i v-if="c.cae_en_no_laborable" class="fas fa-exclamation-triangle text-warning ms-1" title="Cae en día sin cobro"></i>
              </td>
              <td class="text-end">{{ formatoSoles(c.mora_congelada) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="preview.mora_acumulada !== '0.00'" class="mb-3">
        <span class="form-label mb-1 small fw-semibold text-secondary d-block">Mora ya acumulada: {{ formatoSoles(preview.mora_acumulada) }}</span>
        <div class="form-check form-check-inline">
          <input id="rp-mantener" v-model="accionMora" type="radio" class="form-check-input" value="mantener" />
          <label class="form-check-label" for="rp-mantener">Mantenerla (queda como deuda)</label>
        </div>
        <div class="form-check form-check-inline">
          <input id="rp-condonar" v-model="accionMora" type="radio" class="form-check-input" value="condonar" />
          <label class="form-check-label" for="rp-condonar">Condonarla</label>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label mb-1 small fw-semibold text-secondary" for="rp-cargo">Cargo por reprogramar</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text">S/</span>
          <input id="rp-cargo" v-model="cargo" type="text" inputmode="decimal" class="form-control form-control-sm" />
        </div>
        <small class="text-muted">Sugerido: {{ formatoSoles(preview.cargo_sugerido) }}. Se suma a la primera cuota reprogramada.</small>
      </div>
    </template>

    <label class="form-label mb-1 small fw-semibold text-secondary" for="rp-motivo">Motivo <span class="text-danger">*</span></label>
    <textarea id="rp-motivo" v-model="motivo" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
  </DialogoBase>
</template>

<script setup lang="ts">
// Módulo Créditos (00 1.9): reprogramar fechas con vista previa obligatoria. La vista
// previa y la mora a congelar las calcula el backend; aquí solo se arma la solicitud.
import { computed, ref, watch } from 'vue'
import DialogoBase from './DialogoBase.vue'
import { creditoService } from '@/services/admin/creditoService'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { useClaveIdempotencia } from '@/composables/creditos/useClaveIdempotencia'
import { formatoFecha, formatoSoles } from '@/helpers/creditos/formato'
import type { Cuota, PreviewReprogramacion, SolicitudReprogramacion } from '@/types/creditos'

const props = defineProps<{ creditoId: number; cuotas: Cuota[] }>()
const abierto = defineModel<boolean>({ required: true })
const emit = defineEmits<{ hecho: [] }>()

const DEBOUNCE_MS = 350
const modo = ref<'desplazar' | 'editar'>('desplazar')
const desdeCuota = ref<number | null>(null)
const dias = ref('7')
const fechas = ref<Record<number, string>>({})
const accionMora = ref<'mantener' | 'condonar'>('mantener')
const cargo = ref('')
const motivo = ref('')
const preview = ref<PreviewReprogramacion | null>(null)
const cargandoPreview = ref(false)
const errorPreview = ref<string | null>(null)
const procesando = ref(false)
const error = ref<ErrorCredito | null>(null)
const { clave, renovar } = useClaveIdempotencia()
let temporizador: ReturnType<typeof setTimeout> | null = null
let ultima = 0

const pendientes = computed(() => props.cuotas.filter((c) => c.estado === 'pendiente'))

const solicitud = computed<SolicitudReprogramacion | null>(() => {
  if (modo.value === 'desplazar') {
    if (!desdeCuota.value || !/^-?\d+$/.test(dias.value.trim()) || Number(dias.value) === 0) return null
    return { modo: 'desplazar', desde_cuota: desdeCuota.value, dias: Number(dias.value) }
  }
  const originales = Object.fromEntries(pendientes.value.map((c) => [c.numero_cuota, c.fecha_vencimiento]))
  const cambiadas = Object.fromEntries(Object.entries(fechas.value).filter(([n, f]) => f && f !== originales[Number(n)]))
  return Object.keys(cambiadas).length ? { modo: 'editar', fechas: cambiadas } : null
})

watch(abierto, (valor) => {
  if (!valor) return
  renovar()
  error.value = null
  motivo.value = ''
  cargo.value = ''
  accionMora.value = 'mantener'
  desdeCuota.value = pendientes.value[0]?.numero_cuota ?? null
  fechas.value = Object.fromEntries(pendientes.value.map((c) => [c.numero_cuota, c.fecha_vencimiento]))
})

watch(solicitud, (s) => {
  if (temporizador) clearTimeout(temporizador)
  preview.value = null
  errorPreview.value = null
  if (!s || !abierto.value) return
  temporizador = setTimeout(async () => {
    const numero = ++ultima
    cargandoPreview.value = true
    try {
      const resultado = await creditoService.previewReprogramacion(props.creditoId, s)
      if (numero !== ultima) return
      preview.value = resultado
      cargo.value = resultado.cargo
    } catch (e) {
      if (numero === ultima) errorPreview.value = interpretarErrorCredito(e).mensaje
    } finally {
      if (numero === ultima) cargandoPreview.value = false
    }
  }, DEBOUNCE_MS)
}, { deep: true })

async function confirmar() {
  if (!solicitud.value || !preview.value || !motivo.value.trim() || procesando.value) return
  procesando.value = true
  error.value = null
  try {
    await creditoService.reprogramar(props.creditoId, {
      ...solicitud.value,
      accion_mora: accionMora.value,
      cargo: cargo.value.trim().replace(',', '.') || null,
      motivo: motivo.value.trim(),
    }, clave.value)
    renovar()
    abierto.value = false
    emit('hecho')
  } catch (e) {
    error.value = interpretarErrorCredito(e)
  } finally {
    procesando.value = false
  }
}
</script>

<style scoped>
.cifra {
  font-variant-numeric: tabular-nums;
}
.numero {
  width: 2.5rem;
}
.fecha {
  max-width: 11rem;
}
.lista-fechas {
  max-height: 260px;
  overflow-y: auto;
}
</style>
