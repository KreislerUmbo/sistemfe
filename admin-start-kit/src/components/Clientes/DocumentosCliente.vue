<template>
  <div>
    <div class="row g-3">
      <div v-for="t in PRINCIPALES" :key="t" class="col-12 col-sm-4">
        <div class="border rounded p-2 h-100 d-flex flex-column">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="small fw-semibold text-secondary">{{ TEXTO[t] }}</span>
            <span v-if="pendiente(t)" class="badge bg-warning-subtle text-warning-emphasis">Por subir</span>
            <span v-else-if="vistas[t]" class="badge bg-success-subtle text-success-emphasis"><i class="fas fa-check"></i></span>
          </div>
          <button v-if="vistas[t]" type="button" class="vista border rounded mb-2 p-0" :title="`Ver ${TEXTO[t]}`" @click="abrir(vistas[t]!)">
            <img :src="vistas[t]" :alt="TEXTO[t]" />
          </button>
          <div v-else class="vista vista--vacia border rounded mb-2 d-flex align-items-center justify-content-center text-muted">
            <span v-if="cargando" class="spinner-border spinner-border-sm"></span>
            <i v-else :class="t === 'foto_cliente' ? 'fas fa-user' : 'far fa-id-card'" class="fs-3"></i>
          </div>
          <div class="d-flex gap-1 mt-auto">
            <label class="btn btn-sm btn-outline-primary flex-grow-1 mb-0" :class="{ disabled: !puedeSubir || subiendo === t }">
              <span v-if="subiendo === t" class="spinner-border spinner-border-sm me-1"></span>
              <i v-else class="fas fa-camera me-1"></i>{{ vistas[t] ? 'Cambiar' : 'Tomar foto' }}
              <input type="file" accept="image/*" capture="environment" class="d-none" :disabled="!puedeSubir" @change="elegir(t, $event)" />
            </label>
            <label class="btn btn-sm btn-outline-secondary mb-0" :class="{ disabled: !puedeSubir }" :title="`Elegir archivo: ${TEXTO[t]}`">
              <i class="fas fa-folder-open"></i>
              <input type="file" accept="image/jpeg,image/png,image/webp" class="d-none" :disabled="!puedeSubir" @change="elegir(t, $event)" />
            </label>
          </div>
        </div>
      </div>
    </div>

    <!-- Otros documentos (recibo de luz, constancia, etc.) -->
    <div class="mt-3">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="small fw-semibold text-secondary">Otros documentos</span>
        <label class="btn btn-sm btn-outline-secondary mb-0" :class="{ disabled: !puedeSubir || subiendo === 'otro' }">
          <span v-if="subiendo === 'otro'" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="fas fa-plus me-1"></i>Agregar
          <input type="file" accept="image/*" class="d-none" :disabled="!puedeSubir" @change="elegir('otro', $event)" />
        </label>
      </div>
      <div v-if="otros.length" class="d-flex flex-wrap gap-2">
        <button v-for="o in otros" :key="o.clave" type="button" class="miniatura border rounded p-0" title="Ver documento" @click="abrir(o.url)">
          <img :src="o.url" alt="Otro documento" />
        </button>
      </div>
      <small v-else class="text-muted">Sin otros documentos.</small>
    </div>
    <small class="text-muted d-block mt-2">
      <i class="fas fa-lock me-1"></i>Datos personales (Ley 29733): solo los ve quien tiene al cliente en su cartera, y cada vista queda registrada.
    </small>
  </div>
</template>

<script setup lang="ts">
// Documentos del cliente (Fase 4c): DNI por ambos lados, foto y otros. Con cliente guardado
// se suben al momento; en el alta quedan en cola y la página los sube después de crear al
// cliente (subirPendientes). Las fotos se reducen en el navegador antes de subir.
import { onBeforeUnmount, reactive, ref, watch } from 'vue'
import { creditoService } from '@/services/admin/creditoService'
import { interpretarErrorCredito } from '@/composables/creditos/errorCredito'
import { useToast } from '@/composables/useToast'
import { comprimirImagen } from '@/helpers/clientes/comprimirImagen'
import type { TipoArchivoCliente } from '@/types/creditos'

type Archivo = { id: number; tipo: TipoArchivoCliente; created_at: string }

const props = withDefaults(defineProps<{
  clienteId?: number | null
  archivos?: Archivo[]
  puedeSubir?: boolean
}>(), { clienteId: null, archivos: () => [], puedeSubir: true })
const emit = defineEmits<{ subido: [respuesta: unknown] }>()

const PRINCIPALES: TipoArchivoCliente[] = ['dni_anverso', 'dni_reverso', 'foto_cliente']
const TEXTO: Record<TipoArchivoCliente, string> = {
  dni_anverso: 'DNI (anverso)', dni_reverso: 'DNI (reverso)', foto_cliente: 'Foto del cliente', otro: 'Otro',
}

const toast = useToast()
const vistas = reactive<Partial<Record<TipoArchivoCliente, string>>>({})
const otros = ref<{ clave: string; url: string }[]>([])
const cola = ref<{ tipo: TipoArchivoCliente; archivo: File }[]>([])
const cargando = ref(false)
const subiendo = ref<TipoArchivoCliente | null>(null)
const urls = new Set<string>()

const pendiente = (t: TipoArchivoCliente) => cola.value.some((c) => c.tipo === t)
const crearUrl = (blob: Blob) => { const u = URL.createObjectURL(blob); urls.add(u); return u }
const abrir = (url: string) => window.open(url, '_blank', 'noopener')

function liberar() {
  urls.forEach((u) => URL.revokeObjectURL(u))
  urls.clear()
}

/** La más reciente de cada tipo principal y todos los "otros" (vienen de la más nueva a la más vieja). */
async function cargar() {
  if (!props.clienteId) return
  cargando.value = true
  liberar()
  for (const t of PRINCIPALES) delete vistas[t]
  const vistos = new Set<TipoArchivoCliente>()
  const nuevosOtros: { clave: string; url: string }[] = []
  try {
    await Promise.all(props.archivos.filter((a) => a.tipo === 'otro' || (!vistos.has(a.tipo) && vistos.add(a.tipo))).map(async (a) => {
      try {
        const url = crearUrl(await creditoService.archivoCliente(props.clienteId!, a.id))
        if (a.tipo === 'otro') nuevosOtros.push({ clave: `s${a.id}`, url })
        else vistas[a.tipo] = url
      } catch {
        // Una imagen que no carga no debe romper la sección.
      }
    }))
    otros.value = nuevosOtros
  } finally {
    cargando.value = false
  }
}

watch(() => [props.clienteId, props.archivos] as const, cargar, { immediate: true })
onBeforeUnmount(liberar)

async function elegir(tipo: TipoArchivoCliente, evento: Event) {
  const input = evento.target as HTMLInputElement
  const original = input.files?.[0]
  input.value = ''
  if (!original) return
  const archivo = await comprimirImagen(original)
  const url = crearUrl(archivo)

  if (!props.clienteId) {
    // Alta: se sube después de crear al cliente.
    if (tipo === 'otro') {
      cola.value.push({ tipo, archivo })
      otros.value.push({ clave: `c${cola.value.length}`, url })
    } else {
      cola.value = [...cola.value.filter((c) => c.tipo !== tipo), { tipo, archivo }]
      vistas[tipo] = url
    }
    return
  }

  subiendo.value = tipo
  try {
    emit('subido', await creditoService.subirArchivoCliente(props.clienteId, tipo, archivo))
    if (tipo === 'otro') otros.value.unshift({ clave: `n${Date.now()}`, url })
    else vistas[tipo] = url
    toast.success(`${TEXTO[tipo]} guardado`)
  } catch (e) {
    toast.warning(interpretarErrorCredito(e).mensaje)
  } finally {
    subiendo.value = null
  }
}

/** Sube la cola del alta. Devuelve los tipos que fallaron (quedan en cola para reintentar). */
async function subirPendientes(clienteId: number): Promise<TipoArchivoCliente[]> {
  const fallidos: { tipo: TipoArchivoCliente; archivo: File }[] = []
  for (const item of cola.value) {
    subiendo.value = item.tipo
    try {
      await creditoService.subirArchivoCliente(clienteId, item.tipo, item.archivo)
    } catch {
      fallidos.push(item)
    }
  }
  subiendo.value = null
  cola.value = fallidos
  return fallidos.map((f) => f.tipo)
}

const hayPendientes = () => cola.value.length > 0

defineExpose({ subirPendientes, hayPendientes, tiposPendientes: () => cola.value.map((c) => c.tipo) })
</script>

<style scoped>
.vista {
  height: 140px;
  width: 100%;
  overflow: hidden;
  background: var(--bs-tertiary-bg);
}
.vista img,
.miniatura img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.miniatura {
  width: 72px;
  height: 72px;
  overflow: hidden;
}
</style>
