<template>
  <DialogoBase v-model="abierto" titulo="Cambiar asesor" texto-confirmar="Cambiar asesor" variante="primary"
    :procesando="procesando" :deshabilitado="!valido" :error="error" @confirmar="confirmar">
    <p class="small text-muted mb-3">
      Cambia quién figura como asesor de este crédito (quien lo colocó): se usa en el reporte "Por asesor" y en
      comisiones. No mueve al cliente de cartera; para eso está la asignación de cartera del cliente.
    </p>
    <div v-if="cargando" class="small text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
    <div v-else class="row g-3">
      <div class="col-12">
        <span class="small text-secondary">Asesor actual: </span>
        <span class="small fw-semibold">{{ actual?.nombre ?? 'Sin asesor' }}</span>
      </div>
      <div class="col-12">
        <label class="form-label mb-1 small fw-semibold text-secondary" for="ca-asesor">Nuevo asesor</label>
        <select id="ca-asesor" v-model="asesorId" class="form-select form-select-sm">
          <option :value="null" disabled>Elige el asesor</option>
          <option v-for="u in opciones" :key="u.id" :value="u.id">{{ u.nombre }}</option>
        </select>
        <small v-if="!opciones.length" class="text-muted">No hay otros usuarios que puedan registrar créditos.</small>
      </div>
      <div class="col-12">
        <label class="form-label mb-1 small fw-semibold text-secondary" for="ca-motivo">Motivo <span class="text-danger">*</span></label>
        <input id="ca-motivo" v-model="motivo" type="text" class="form-control form-control-sm" maxlength="500"
          placeholder="Ej.: lo registró soporte a nombre de la asesora" />
      </div>
    </div>
  </DialogoBase>
</template>

<script setup lang="ts">
// Módulo Créditos (08-oct-2026): cambiar el asesor de un crédito activo o castigado. Requiere
// creditos.cartera.asignar; el backend valida estado, permiso de quien recibe y deja auditoría.
import { computed, ref, watch } from 'vue'
import DialogoBase from './DialogoBase.vue'
import { creditoService } from '@/services/admin/creditoService'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { useClaveIdempotencia } from '@/composables/creditos/useClaveIdempotencia'
import type { UsuarioCartera } from '@/types/creditos'

const props = defineProps<{ creditoId: number; actual: UsuarioCartera | null }>()
const abierto = defineModel<boolean>({ required: true })
const emit = defineEmits<{ hecho: [] }>()

const asesores = ref<UsuarioCartera[]>([])
const asesorId = ref<number | null>(null)
const motivo = ref('')
const cargando = ref(false)
const procesando = ref(false)
const error = ref<ErrorCredito | null>(null)
const { clave, renovar } = useClaveIdempotencia()

const opciones = computed(() => asesores.value.filter((u) => u.id !== props.actual?.id))
const valido = computed(() => asesorId.value !== null && motivo.value.trim() !== '')

watch(abierto, async (visible) => {
  if (!visible) return
  asesorId.value = null
  motivo.value = ''
  error.value = null
  renovar()
  cargando.value = true
  try {
    asesores.value = (await creditoService.usuariosCartera()).asesores
  } catch (e) {
    error.value = interpretarErrorCredito(e)
  } finally {
    cargando.value = false
  }
})

async function confirmar() {
  if (!valido.value || asesorId.value === null || procesando.value) return
  procesando.value = true
  error.value = null
  try {
    await creditoService.cambiarAsesor(props.creditoId, asesorId.value, motivo.value.trim(), clave.value)
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
