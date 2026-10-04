<template>
  <DefaultLayout>
    <EncabezadoCredito titulo="Configuración de créditos" subtitulo="Valores por defecto del negocio, feriados y contrato" icono="fas fa-sliders-h" :volver="false" />

    <ul class="nav nav-pills mb-3">
      <li v-for="t in PESTANAS" :key="t.id" class="nav-item">
        <button type="button" class="nav-link" :class="{ active: pestana === t.id }" @click="cambiarPestana(t.id)">
          <i :class="t.icono" class="me-1"></i>{{ t.texto }}
        </button>
      </li>
    </ul>

    <PlantillaContrato v-if="pestana === 'contrato'" />

    <div v-else-if="pestana === 'feriados'" class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-2 fw-semibold text-dark">Feriados</div>
      <div class="card-body py-3">
        <FeriadosCredito />
      </div>
    </div>

    <template v-else>
      <div v-if="cargando" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
      <div v-else-if="errorCarga" class="alert alert-danger">{{ errorCarga }}</div>

      <form v-else-if="form" novalidate @submit.prevent="guardar">
        <div v-for="s in SECCIONES_CONFIG" :key="s.titulo" class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-white border-bottom py-2">
            <span class="fw-semibold text-dark">{{ s.titulo }}</span>
            <small v-if="s.descripcion" class="text-muted d-block">{{ s.descripcion }}</small>
          </div>
          <div class="card-body py-3">
            <div class="row g-3">
              <template v-for="c in s.campos" :key="c.clave">
                <div v-if="!c.visible || c.visible(form)" :class="c.tipo === 'dias' || c.tipo === 'lista' ? 'col-12' : 'col-12 col-md-6 col-xl-4'">
                  <!-- Días sin cobro -->
                  <template v-if="c.tipo === 'dias'">
                    <span class="form-label mb-1 small fw-semibold text-secondary d-block">{{ c.etiqueta }}</span>
                    <div class="d-flex flex-wrap gap-1" role="group" :aria-label="c.etiqueta">
                      <button v-for="(d, i) in DIAS" :key="d" type="button" class="btn btn-sm" :aria-pressed="diaMarcado(i + 1)"
                        :class="diaMarcado(i + 1) ? 'btn-secondary' : 'btn-outline-secondary'" @click="alternarDia(i + 1)">
                        {{ d }}
                      </button>
                    </div>
                    <small class="text-muted">Marcados: no se cobra ese día.</small>
                  </template>

                  <!-- Varias casillas (ej. ficha exigida) -->
                  <template v-else-if="c.tipo === 'lista'">
                    <span class="form-label mb-1 small fw-semibold text-secondary d-block">{{ c.etiqueta }}</span>
                    <div class="row g-1">
                      <div v-for="o in c.opciones" :key="o.valor" class="col-12 col-sm-6 col-lg-3">
                        <div class="form-check mb-0">
                          <input :id="`cfg-${c.clave}-${o.valor}`" v-model="form[c.clave]" class="form-check-input" type="checkbox" :value="o.valor" />
                          <label class="form-check-label small" :for="`cfg-${c.clave}-${o.valor}`">{{ o.texto }}</label>
                        </div>
                      </div>
                    </div>
                    <small v-if="c.ayuda" class="text-muted">{{ c.ayuda }}</small>
                  </template>

                  <div v-else-if="c.tipo === 'booleano'" class="form-check form-switch pt-md-4 mb-0">
                    <input :id="`cfg-${c.clave}`" v-model="form[c.clave]" class="form-check-input" type="checkbox" role="switch" />
                    <label class="form-check-label small" :for="`cfg-${c.clave}`">{{ c.etiqueta }}</label>
                  </div>

                  <template v-else>
                    <label class="form-label mb-1 small fw-semibold text-secondary" :for="`cfg-${c.clave}`">{{ c.etiqueta }}</label>
                    <select v-if="c.tipo === 'select'" :id="`cfg-${c.clave}`" v-model="form[c.clave]" class="form-select form-select-sm"
                      :class="{ 'is-invalid': errores[c.clave] }">
                      <option v-for="o in c.opciones" :key="o.valor" :value="o.valor">{{ o.texto }}</option>
                    </select>
                    <div v-else class="input-group input-group-sm">
                      <span v-if="c.tipo === 'soles'" class="input-group-text">S/</span>
                      <input :id="`cfg-${c.clave}`" v-model="form[c.clave]" type="text" class="form-control"
                        :inputmode="c.tipo === 'entero' ? 'numeric' : 'decimal'" :placeholder="c.opcional ? 'Sin límite' : ''"
                        :class="{ 'is-invalid': errores[c.clave] }" />
                      <span v-if="c.tipo === 'porcentaje'" class="input-group-text">%</span>
                    </div>
                    <div v-if="errores[c.clave]" class="invalid-feedback d-block">{{ errores[c.clave] }}</div>
                    <small v-else-if="c.ayuda" class="text-muted">{{ c.ayuda }}</small>
                  </template>
                </div>
              </template>
            </div>
          </div>
        </div>

        <BarraAccionMovil>
          <button type="button" class="btn btn-outline-secondary" :disabled="!hayCambios || guardando" @click="descartar">
            <i class="fas fa-undo me-2"></i>Descartar
          </button>
          <button type="submit" class="btn btn-primary fw-semibold" :disabled="!hayCambios || guardando">
            <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span><i v-else class="fas fa-save me-2"></i>
            Guardar{{ cantidadCambios ? ` (${cantidadCambios})` : '' }}
          </button>
        </BarraAccionMovil>
      </form>
    </template>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos — Configuración y feriados (04-frontend pantalla 7). Los defaults se copian
// a cada crédito nuevo y quedan congelados al activarlo; se guarda solo lo que cambió.
import { computed, onMounted, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import BarraAccionMovil from '@/components/Creditos/BarraAccionMovil.vue'
import EncabezadoCredito from '@/components/Creditos/EncabezadoCredito.vue'
import FeriadosCredito from '@/components/Creditos/FeriadosCredito.vue'
import PlantillaContrato from '@/components/Creditos/PlantillaContrato.vue'
import { creditoService } from '@/services/admin/creditoService'
import { useCreditosCatalogosStore } from '@/stores/creditosCatalogos'
import { useToast } from '@/composables/useToast'
import { interpretarErrorCredito } from '@/composables/creditos/errorCredito'
import { cambiosConfig, formularioConfig, SECCIONES_CONFIG, type FormConfig } from '@/helpers/creditos/configuracion'

const PESTANAS = [
  { id: 'negocio', texto: 'Valores del negocio', icono: 'fas fa-sliders-h' },
  { id: 'feriados', texto: 'Feriados', icono: 'far fa-calendar-alt' },
  { id: 'contrato', texto: 'Contrato', icono: 'fas fa-file-signature' },
] as const
type IdPestana = (typeof PESTANAS)[number]['id']
const DIAS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']

const route = useRoute()
const router = useRouter()
const toast = useToast()
const catalogos = useCreditosCatalogosStore()

const pestana = computed<IdPestana>(() => (route.query.tab === 'feriados' || route.query.tab === 'contrato' ? route.query.tab : 'negocio'))
const original = ref<FormConfig | null>(null)
const form = ref<FormConfig | null>(null)
const cargando = ref(true)
const guardando = ref(false)
const errorCarga = ref<string | null>(null)
const errores = ref<Record<string, string>>({})

const cantidadCambios = computed(() => (form.value && original.value ? Object.keys(cambiosConfig(original.value, form.value).cambios).length : 0))
const hayCambios = computed(() => {
  if (!form.value || !original.value) return false
  const { cambios, errores: locales } = cambiosConfig(original.value, form.value)
  return Object.keys(cambios).length > 0 || Object.keys(locales).length > 0
})

onMounted(async () => {
  try {
    const config = await creditoService.configuracion()
    original.value = formularioConfig(config)
    form.value = formularioConfig(config)
  } catch (e) {
    errorCarga.value = interpretarErrorCredito(e).mensaje
  } finally {
    cargando.value = false
  }
})

function cambiarPestana(id: (typeof PESTANAS)[number]['id']) {
  router.replace({ query: id === 'negocio' ? {} : { tab: id } })
}

const diaMarcado = (dia: number) => ((form.value?.dias_no_laborables as number[]) ?? []).includes(dia)

function alternarDia(dia: number) {
  if (!form.value) return
  const actuales = form.value.dias_no_laborables as number[]
  form.value.dias_no_laborables = actuales.includes(dia) ? actuales.filter((d) => d !== dia) : [...actuales, dia].sort()
}

async function guardar() {
  if (!form.value || !original.value || guardando.value) return
  const { cambios, errores: locales } = cambiosConfig(original.value, form.value)
  errores.value = locales
  if (Object.keys(locales).length) {
    toast.warning('Revisa los campos marcados.')
    return
  }
  if (!Object.keys(cambios).length) return

  guardando.value = true
  try {
    const guardada = await creditoService.guardarConfiguracion(cambios)
    original.value = formularioConfig(guardada)
    form.value = formularioConfig(guardada)
    catalogos.invalidarConfiguracion()
    toast.success('Configuración guardada')
  } catch (e) {
    const error = interpretarErrorCredito(e)
    if (error.tipo === 'validacion') errores.value = error.campos
    toast.warning(error.tipo === 'validacion' ? 'Revisa los campos marcados.' : error.mensaje)
  } finally {
    guardando.value = false
  }
}

function descartar() {
  if (!original.value) return
  form.value = { ...original.value, dias_no_laborables: [...(original.value.dias_no_laborables as number[])] }
  errores.value = {}
}

onBeforeRouteLeave((destino) => {
  // Cambiar de pestaña es la misma vista: no se pierde nada.
  if (destino.name === route.name || !hayCambios.value) return true
  return window.confirm('Hay cambios sin guardar. ¿Salir de todas formas?')
})
</script>

