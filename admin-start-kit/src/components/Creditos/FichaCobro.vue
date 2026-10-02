<template>
  <div class="small">
    <div v-if="cargando" class="text-muted py-2"><span class="spinner-border spinner-border-sm me-2"></span>Cargando ficha…</div>
    <div v-else-if="error" class="text-danger py-2">{{ error }}</div>

    <template v-else>
      <div class="d-flex align-items-start gap-3 mb-3">
        <div class="foto flex-shrink-0">
          <img v-if="imagenes.foto_cliente" :src="imagenes.foto_cliente" :alt="`Foto de ${cliente.nombre}`" />
          <i v-else class="fas fa-user text-muted"></i>
        </div>
        <div class="min-w-0">
          <div class="fw-semibold fs-6">{{ cliente.nombre }}</div>
          <div class="text-muted">DNI {{ cliente.documento ?? '—' }}</div>
          <div v-if="ficha?.ocupacion" class="text-muted">{{ ficha.ocupacion }}</div>
        </div>
      </div>

      <!-- Dirección de cobro -->
      <div class="mb-2">
        <div class="etiqueta">Dirección de cobro{{ ficha?.tipo_direccion ? ` · ${ficha.tipo_direccion === 'casa' ? 'Casa' : 'Negocio'}` : '' }}</div>
        <div>{{ ficha?.direccion_cobro || 'Sin dirección registrada' }}</div>
        <div v-if="ficha?.referencia" class="text-muted">{{ ficha.referencia }}</div>
        <a v-if="mapa" :href="mapa" target="_blank" rel="noopener" class="d-inline-block mt-1"><i class="fas fa-map-marker-alt me-1"></i>Abrir mapa</a>
      </div>

      <!-- Teléfonos -->
      <div class="mb-2">
        <div class="etiqueta">Teléfonos</div>
        <div v-for="t in telefonos" :key="t" class="d-flex align-items-center gap-2">
          <a :href="enlaceLlamada(t) ?? undefined"><i class="fas fa-phone me-1"></i>{{ t }}</a>
          <a v-if="enlaceWhatsapp(t)" :href="enlaceWhatsapp(t)!" target="_blank" rel="noopener" class="text-success" :aria-label="`WhatsApp ${t}`">
            <i class="fab fa-whatsapp"></i>
          </a>
        </div>
        <div v-if="!telefonos.length" class="text-muted">Sin teléfono</div>
      </div>

      <!-- Documentos -->
      <div class="mb-2">
        <div class="etiqueta">DNI</div>
        <div class="d-flex gap-2">
          <component :is="imagenes[t] ? 'a' : 'div'" v-for="t in ['dni_anverso', 'dni_reverso'] as const" :key="t"
            :href="imagenes[t] || undefined" target="_blank" rel="noopener" class="miniatura" :title="TEXTO_ARCHIVO[t]">
            <img v-if="imagenes[t]" :src="imagenes[t]" :alt="TEXTO_ARCHIVO[t]" />
            <span v-else class="text-muted">{{ TEXTO_ARCHIVO[t] }}</span>
          </component>
        </div>
      </div>

      <div v-if="ficha?.notas" class="mb-2">
        <div class="etiqueta">Notas</div>
        <div class="text-pre">{{ ficha.notas }}</div>
      </div>

      <!-- Cartera: asesor (que también cobra) o cobrador, según la configuración -->
      <div class="mb-3">
        <label class="etiqueta d-block" for="ficha-cobrador">{{ etiquetaCartera }}</label>
        <select v-if="puedeAsignar" id="ficha-cobrador" v-model="cobradorId" class="form-select form-select-sm" :disabled="asignando" @change="asignar">
          <option :value="null">Sin asignar</option>
          <option v-for="c in cobradores" :key="c.id" :value="c.id">{{ c.nombre }}</option>
        </select>
        <div v-else>{{ nombreCobrador ?? 'Sin asignar' }}</div>
      </div>

      <button v-if="puedeEditar" type="button" class="btn btn-sm btn-outline-primary w-100" @click="abrirEdicion">
        <i class="fas fa-pen me-1"></i>Editar ficha
      </button>
    </template>

    <!-- Edición -->
    <DialogoBase v-model="editando" titulo="Ficha de cobro" texto-confirmar="Guardar" :procesando="guardando" :error="errorGuardar" tamano="lg" @confirmar="guardar">
      <div class="row g-3">
        <div class="col-12 col-md-8">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="ficha-dir">Dirección de cobro</label>
          <input id="ficha-dir" v-model="borrador.direccion_cobro" type="text" class="form-control form-control-sm" maxlength="255" />
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="ficha-tipo">Tipo</label>
          <select id="ficha-tipo" v-model="borrador.tipo_direccion" class="form-select form-select-sm">
            <option :value="null">—</option>
            <option value="casa">Casa</option>
            <option value="negocio">Negocio</option>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="ficha-ref">Referencia</label>
          <input id="ficha-ref" v-model="borrador.referencia" type="text" class="form-control form-control-sm" maxlength="255" placeholder="Ej.: frente al mercado, puerta verde" />
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="ficha-lat">Latitud</label>
          <input id="ficha-lat" v-model="borrador.latitud" type="text" inputmode="decimal" class="form-control form-control-sm" />
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="ficha-lng">Longitud</label>
          <input id="ficha-lng" v-model="borrador.longitud" type="text" inputmode="decimal" class="form-control form-control-sm" />
        </div>
        <div class="col-12 col-md-4 d-flex align-items-end">
          <button type="button" class="btn btn-sm btn-outline-primary w-100" :disabled="ubicando" @click="usarUbicacion">
            <span v-if="ubicando" class="spinner-border spinner-border-sm me-1"></span><i v-else class="fas fa-crosshairs me-1"></i>Mi ubicación
          </button>
        </div>
        <small v-if="errorUbicacion" class="col-12 text-danger">{{ errorUbicacion }}</small>
        <div class="col-12 col-md-6">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="ficha-tel2">Teléfono alterno</label>
          <input id="ficha-tel2" v-model="borrador.telefono_alterno" type="tel" class="form-control form-control-sm" maxlength="20" />
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="ficha-ocup">Ocupación</label>
          <input id="ficha-ocup" v-model="borrador.ocupacion" type="text" class="form-control form-control-sm" maxlength="150" />
        </div>
        <div class="col-12">
          <label class="form-label mb-1 small fw-semibold text-secondary" for="ficha-notas">Notas</label>
          <textarea id="ficha-notas" v-model="borrador.notas" class="form-control form-control-sm" rows="2" maxlength="2000"></textarea>
        </div>
        <div class="col-12">
          <div class="form-label mb-1 small fw-semibold text-secondary">Fotos</div>
          <div class="d-flex flex-wrap gap-2">
            <label v-for="t in TIPOS_FOTO" :key="t" class="btn btn-sm btn-light mb-0" :class="{ disabled: subiendo === t }">
              <span v-if="subiendo === t" class="spinner-border spinner-border-sm me-1"></span><i v-else class="fas fa-camera me-1"></i>
              {{ TEXTO_ARCHIVO[t] }}{{ imagenes[t] ? ' ✓' : '' }}
              <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="d-none" @change="subir(t, $event)" />
            </label>
          </div>
          <small class="text-muted">JPG, PNG o WEBP, hasta 10 MB. Se guardan al elegirlas.</small>
        </div>
      </div>
    </DialogoBase>
  </div>
</template>

<script setup lang="ts">
// Módulo Créditos — Ficha de cobro (04-frontend pantalla 8): dirección, referencia, mapa,
// teléfonos, DNI y foto, cobrador asignado. Es dato personal (Ley 29733): el backend solo
// la entrega a quien ve toda la cartera o al cobrador asignado.
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import DialogoBase from './DialogoBase.vue'
import { creditoService } from '@/services/admin/creditoService'
import { interpretarErrorCredito, type ErrorCredito } from '@/composables/creditos/errorCredito'
import { useToast } from '@/composables/useToast'
import { enlaceLlamada, enlaceMapa, enlaceWhatsapp } from '@/helpers/creditos/cobranza'
import type { DatosFicha, FichaCliente, TipoArchivoCliente } from '@/types/creditos'

const props = defineProps<{
  cliente: { id: number; nombre: string; documento: string | null; telefono: string | null }
  puedeEditar: boolean
  puedeAsignar: boolean
}>()

const TEXTO_ARCHIVO: Record<TipoArchivoCliente, string> = {
  foto_cliente: 'Foto del cliente', dni_anverso: 'DNI anverso', dni_reverso: 'DNI reverso', otro: 'Otro',
}
const TIPOS_FOTO: TipoArchivoCliente[] = ['foto_cliente', 'dni_anverso', 'dni_reverso']
const VACIA: DatosFicha = { direccion_cobro: null, tipo_direccion: null, referencia: null, latitud: null, longitud: null, telefono_alterno: null, ocupacion: null, notas: null }

const toast = useToast()
const datos = ref<FichaCliente | null>(null)
const cargando = ref(false)
const error = ref<string | null>(null)
const imagenes = reactive<Partial<Record<TipoArchivoCliente, string>>>({})
const cobradores = ref<{ id: number; nombre: string }[]>([])
const cobradorId = ref<number | null>(null)
const asignando = ref(false)
const editando = ref(false)
const guardando = ref(false)
const errorGuardar = ref<ErrorCredito | null>(null)
const borrador = ref<DatosFicha>({ ...VACIA })
const ubicando = ref(false)
const errorUbicacion = ref<string | null>(null)
const subiendo = ref<TipoArchivoCliente | null>(null)

const ficha = computed(() => datos.value?.ficha ?? null)
const mapa = computed(() => (ficha.value ? enlaceMapa(ficha.value) : null))
const telefonos = computed(() => [props.cliente.telefono, ficha.value?.telefono_alterno].filter((t): t is string => !!t?.trim()))
const nombreCobrador = computed(() => cobradores.value.find((c) => c.id === cobradorId.value)?.nombre ?? null)
const asesorCobra = computed(() => datos.value?.cartera.asesor_cobra ?? true)
const etiquetaCartera = computed(() => (asesorCobra.value ? 'Asesor (también cobra)' : 'Cobrador asignado'))
/** Valor del selector: el asesor si también cobra; si no, el cobrador. */
const asignadoActual = (f: FichaCliente) => (f.cartera.asesor_cobra ? f.cartera.asesor_id : f.cartera.cobrador_id)

function liberarImagenes() {
  for (const t of Object.keys(imagenes) as TipoArchivoCliente[]) {
    URL.revokeObjectURL(imagenes[t]!)
    delete imagenes[t]
  }
}

/** La más reciente de cada tipo (vienen ordenadas de la más nueva a la más vieja). */
async function cargarImagenes() {
  liberarImagenes()
  const vistas = new Set<TipoArchivoCliente>()
  await Promise.all((datos.value?.archivos ?? []).filter((a) => {
    if (a.tipo === 'otro' || vistas.has(a.tipo)) return false
    vistas.add(a.tipo)
    return true
  }).map(async (a) => {
    try {
      imagenes[a.tipo] = URL.createObjectURL(await creditoService.archivoCliente(props.cliente.id, a.id))
    } catch {
      // Una imagen que no carga no debe romper la ficha.
    }
  }))
}

async function cargar() {
  cargando.value = true
  error.value = null
  try {
    const [f, usuarios] = await Promise.all([
      creditoService.fichaCliente(props.cliente.id),
      props.puedeAsignar ? creditoService.usuariosCartera() : Promise.resolve(null),
    ])
    datos.value = f
    cobradores.value = usuarios ? (f.cartera.asesor_cobra ? usuarios.asesores : usuarios.cobradores) : []
    cobradorId.value = asignadoActual(f)
    await cargarImagenes()
  } catch (e) {
    error.value = interpretarErrorCredito(e).mensaje
  } finally {
    cargando.value = false
  }
}

watch(() => props.cliente.id, cargar, { immediate: true })
onBeforeUnmount(liberarImagenes)

async function asignar() {
  asignando.value = true
  try {
    const cartera = datos.value!.cartera
    const nueva = asesorCobra.value
      ? await creditoService.asignarCartera(props.cliente.id, cobradorId.value)
      : await creditoService.asignarCartera(props.cliente.id, cartera.asesor_id, cobradorId.value)
    toast.success(cobradorId.value ? `Cartera asignada a ${nombreCobrador.value}` : 'Cliente sin asignar')
    datos.value!.cartera = nueva
  } catch (e) {
    toast.warning(interpretarErrorCredito(e).mensaje)
    cobradorId.value = datos.value ? asignadoActual(datos.value) : null
  } finally {
    asignando.value = false
  }
}

function abrirEdicion() {
  borrador.value = { ...VACIA, ...(ficha.value ?? {}) }
  errorGuardar.value = null
  errorUbicacion.value = null
  editando.value = true
}

async function guardar() {
  guardando.value = true
  errorGuardar.value = null
  const limpio = Object.fromEntries(
    Object.entries(borrador.value).map(([k, v]) => [k, typeof v === 'string' ? v.trim() || null : v]),
  ) as DatosFicha
  try {
    const guardada = await creditoService.guardarFicha(props.cliente.id, limpio)
    if (datos.value) datos.value.ficha = guardada
    editando.value = false
    toast.success('Ficha actualizada')
  } catch (e) {
    const interpretado = interpretarErrorCredito(e)
    if (interpretado.tipo === 'validacion') interpretado.mensaje = Object.values(interpretado.campos).join(' ')
    errorGuardar.value = interpretado
  } finally {
    guardando.value = false
  }
}

function usarUbicacion() {
  errorUbicacion.value = null
  if (!('geolocation' in navigator)) {
    errorUbicacion.value = 'Este navegador no permite obtener la ubicación.'
    return
  }
  ubicando.value = true
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      borrador.value.latitud = pos.coords.latitude.toFixed(7)
      borrador.value.longitud = pos.coords.longitude.toFixed(7)
      ubicando.value = false
    },
    () => {
      errorUbicacion.value = 'No se pudo obtener la ubicación (revisa el permiso del navegador; requiere conexión segura https).'
      ubicando.value = false
    },
    { enableHighAccuracy: true, timeout: 15_000 },
  )
}

async function subir(tipo: TipoArchivoCliente, evento: Event) {
  const input = evento.target as HTMLInputElement
  const archivo = input.files?.[0]
  input.value = ''
  if (!archivo) return
  subiendo.value = tipo
  try {
    const nuevo = await creditoService.subirArchivoCliente(props.cliente.id, tipo, archivo)
    datos.value?.archivos.unshift(nuevo)
    if (imagenes[tipo]) URL.revokeObjectURL(imagenes[tipo]!)
    imagenes[tipo] = URL.createObjectURL(archivo)
    toast.success(`${TEXTO_ARCHIVO[tipo]} guardada`)
  } catch (e) {
    const interpretado = interpretarErrorCredito(e)
    toast.warning(interpretado.tipo === 'validacion' ? Object.values(interpretado.campos).join(' ') : interpretado.mensaje)
  } finally {
    subiendo.value = null
  }
}
</script>

<style scoped>
.etiqueta {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--bs-secondary-color);
  margin-bottom: 0.1rem;
}
.foto {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  overflow: hidden;
  background: var(--bs-tertiary-bg);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.4rem;
}
.foto img,
.miniatura img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.miniatura {
  width: 96px;
  height: 64px;
  border-radius: 8px;
  overflow: hidden;
  border: 1px dashed var(--bs-border-color);
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  font-size: 0.7rem;
}
.min-w-0 {
  min-width: 0;
}
.text-pre {
  white-space: pre-line;
}
</style>
