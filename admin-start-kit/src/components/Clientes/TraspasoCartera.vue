<template>
  <DialogoBase v-model="abierto" titulo="Traspasar cartera" texto-confirmar="Traspasar" :procesando="procesando" :error="error"
    :deshabilitado="!valido" tamano="lg" @confirmar="traspasar">
    <p class="small text-muted mb-3">
      Mueve todos los clientes de un usuario (que se va o cambia de zona) a otro. Queda en el historial de cada cliente;
      los créditos ya entregados conservan a quien los colocó.
    </p>
    <div v-if="cargando" class="small text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
    <div v-else class="row g-3">
      <div class="col-12 col-md-6">
        <label class="form-label mb-1 small fw-semibold text-secondary" for="tr-desde">Desde</label>
        <select id="tr-desde" v-model="desde" class="form-select form-select-sm">
          <option :value="null" disabled>Elige quién entrega la cartera</option>
          <option v-for="t in titulares" :key="t.id" :value="t.id">
            {{ t.nombre }}{{ t.eliminado ? ' (eliminado)' : !t.activo ? ' (inactivo)' : '' }} · {{ Math.max(t.asesor, t.cobrador) }} cliente(s)
          </option>
        </select>
        <small v-if="!titulares.length" class="text-muted">Nadie tiene clientes asignados todavía.</small>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label mb-1 small fw-semibold text-secondary" for="tr-hacia">Hacia</label>
        <select id="tr-hacia" v-model="hacia" class="form-select form-select-sm">
          <option :value="null" disabled>Elige quién la recibe</option>
          <option v-for="u in destinos" :key="u.id" :value="u.id">{{ u.nombre }}</option>
        </select>
      </div>
      <div v-if="!asesorCobra" class="col-12">
        <span class="form-label mb-1 small fw-semibold text-secondary d-block">Qué se traspasa</span>
        <div class="btn-group btn-group-sm" role="group" aria-label="Funciones a traspasar">
          <template v-for="o in OPCIONES" :key="o.valor">
            <input :id="`tr-${o.valor}`" v-model="funciones" type="radio" class="btn-check" :value="o.valor" />
            <label class="btn btn-outline-secondary" :for="`tr-${o.valor}`">{{ o.texto }}</label>
          </template>
        </div>
      </div>
      <div v-if="seleccionado" class="col-12">
        <div class="alert alert-info py-2 small mb-0">
          Se moverán <b>{{ cantidad }}</b> cliente(s) de {{ seleccionado.nombre }}
          a {{ destinos.find((u) => u.id === hacia)?.nombre ?? '…' }}.
        </div>
      </div>
    </div>
  </DialogoBase>
</template>

<script setup lang="ts">
// Traspasar toda la cartera de un usuario a otro (Fase 4c.1). Solo con creditos.cartera.asignar
// y creditos.ver_todos; el backend lo vuelve a exigir y valida los permisos de quien recibe.
import { computed, ref, watch } from 'vue'
import DialogoBase from '@/components/Creditos/DialogoBase.vue'
import { creditoService } from '@/services/admin/creditoService'
import { useCreditosCatalogosStore } from '@/stores/creditosCatalogos'
import { useClaveIdempotencia } from '@/composables/creditos/useClaveIdempotencia'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { useToast } from '@/composables/useToast'
import type { TitularCartera, UsuarioCartera } from '@/types/creditos'

const abierto = defineModel<boolean>({ required: true })
const emit = defineEmits<{ hecho: [] }>()

type Funciones = 'ambas' | 'asesor' | 'cobrador'
const OPCIONES: { valor: Funciones; texto: string }[] = [
  { valor: 'ambas', texto: 'Asesor y cobrador' },
  { valor: 'asesor', texto: 'Solo como asesor' },
  { valor: 'cobrador', texto: 'Solo como cobrador' },
]

const toast = useToast()
const catalogos = useCreditosCatalogosStore()
const { clave, renovar } = useClaveIdempotencia()
const cargando = ref(false)
const procesando = ref(false)
const error = ref<ErrorCredito | null>(null)
const titulares = ref<TitularCartera[]>([])
const usuarios = ref<{ asesores: UsuarioCartera[]; cobradores: UsuarioCartera[] }>({ asesores: [], cobradores: [] })
const asesorCobra = ref(true)
const desde = ref<number | null>(null)
const hacia = ref<number | null>(null)
const funciones = ref<Funciones>('ambas')

const seleccionado = computed(() => titulares.value.find((t) => t.id === desde.value) ?? null)
// Quien recibe necesita el permiso de lo que se traspasa; el que entrega no puede recibir.
const destinos = computed(() => (funciones.value === 'cobrador' && !asesorCobra.value ? usuarios.value.cobradores : usuarios.value.asesores)
  .filter((u) => u.id !== desde.value))
const cantidad = computed(() => {
  const t = seleccionado.value
  if (!t) return 0
  if (asesorCobra.value || funciones.value === 'ambas') return Math.max(t.asesor, t.cobrador)
  return funciones.value === 'asesor' ? t.asesor : t.cobrador
})
const valido = computed(() => desde.value !== null && hacia.value !== null && cantidad.value > 0)

watch(abierto, async (visible) => {
  if (!visible) return
  desde.value = null
  hacia.value = null
  funciones.value = 'ambas'
  error.value = null
  cargando.value = true
  try {
    const [t, u, config] = await Promise.all([
      creditoService.titularesCartera(), creditoService.usuariosCartera(), catalogos.obtenerConfiguracion(),
    ])
    titulares.value = t
    usuarios.value = u
    asesorCobra.value = config.asesor_cobra ?? true
  } catch (e) {
    error.value = interpretarErrorCredito(e)
  } finally {
    cargando.value = false
  }
})

async function traspasar() {
  if (!valido.value || desde.value === null || hacia.value === null) return
  procesando.value = true
  error.value = null
  try {
    const { clientes } = await creditoService.traspasarCartera({
      desde_usuario_id: desde.value, hacia_usuario_id: hacia.value, funciones: asesorCobra.value ? 'ambas' : funciones.value,
    }, clave.value)
    renovar()
    abierto.value = false
    toast.success(`Se traspasaron ${clientes} cliente(s)`)
    emit('hecho')
  } catch (e) {
    error.value = interpretarErrorCredito(e)
  } finally {
    procesando.value = false
  }
}
</script>
