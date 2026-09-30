<template>
  <DefaultLayout>
    <div class="creditos-contenedor mx-auto">
      <div class="d-flex align-items-center gap-2 mb-3">
        <h5 class="fw-bold mb-0 flex-grow-1">Configuración de créditos</h5>
      </div>

      <ul class="nav nav-pills gap-1 mb-3" role="tablist">
        <li v-for="t in PESTANAS" :key="t.id" class="nav-item" role="presentation">
          <button type="button" class="nav-link rounded-pill px-3 py-2" :class="{ active: pestana === t.id }" role="tab"
            :aria-selected="pestana === t.id" @click="cambiarPestana(t.id)">
            {{ t.texto }}
          </button>
        </li>
      </ul>

      <div v-if="pestana === 'feriados'" class="card border-0 shadow-sm">
        <div class="card-body p-3 p-md-4">
          <FeriadosCredito />
        </div>
      </div>

      <template v-else>
        <div v-if="cargando" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
        <div v-else-if="errorCarga" class="alert alert-danger">{{ errorCarga }}</div>

        <form v-else-if="form" novalidate @submit.prevent="guardar">
          <div v-for="s in SECCIONES_CONFIG" :key="s.titulo" class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 p-md-4">
              <h6 class="fw-bold mb-1">{{ s.titulo }}</h6>
              <p v-if="s.descripcion" class="small text-muted mb-3">{{ s.descripcion }}</p>
              <div class="row g-3" :class="{ 'mt-0': !s.descripcion }">
                <template v-for="c in s.campos" :key="c.clave">
                  <div v-if="!c.visible || c.visible(form)" :class="c.tipo === 'dias' ? 'col-12' : 'col-12 col-md-6 col-xl-4'">
                    <!-- Días sin cobro: botones que se activan -->
                    <template v-if="c.tipo === 'dias'">
                      <div class="form-label small fw-semibold">{{ c.etiqueta }}</div>
                      <div class="d-flex flex-wrap gap-2" role="group" :aria-label="c.etiqueta">
                        <button v-for="(d, i) in DIAS" :key="d" type="button" class="btn boton-dia" :aria-pressed="diaMarcado(i + 1)"
                          :class="diaMarcado(i + 1) ? 'btn-dark' : 'btn-outline-secondary'" @click="alternarDia(i + 1)">
                          {{ d }}
                        </button>
                      </div>
                      <small class="text-muted">Marcados: no se cobra ese día.</small>
                    </template>

                    <div v-else-if="c.tipo === 'booleano'" class="form-check form-switch pt-md-4">
                      <input :id="`cfg-${c.clave}`" v-model="form[c.clave]" class="form-check-input" type="checkbox" role="switch" />
                      <label class="form-check-label" :for="`cfg-${c.clave}`">{{ c.etiqueta }}</label>
                    </div>

                    <template v-else>
                      <label class="form-label small fw-semibold" :for="`cfg-${c.clave}`">{{ c.etiqueta }}</label>
                      <select v-if="c.tipo === 'select'" :id="`cfg-${c.clave}`" v-model="form[c.clave]" class="form-select boton-alto"
                        :class="{ 'is-invalid': errores[c.clave] }">
                        <option v-for="o in c.opciones" :key="o.valor" :value="o.valor">{{ o.texto }}</option>
                      </select>
                      <div v-else class="input-group">
                        <span v-if="c.tipo === 'soles'" class="input-group-text">S/</span>
                        <input :id="`cfg-${c.clave}`" v-model="form[c.clave]" type="text" class="form-control boton-alto"
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
            <button type="button" class="btn btn-light flex-grow-1 flex-md-grow-0 px-md-4 boton-alto" :disabled="!hayCambios || guardando" @click="descartar">
              Descartar
            </button>
            <button type="submit" class="btn btn-primary flex-grow-1 flex-md-grow-0 px-md-4 boton-alto" :disabled="!hayCambios || guardando">
              <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
              Guardar{{ cantidadCambios ? ` (${cantidadCambios})` : '' }}
            </button>
          </BarraAccionMovil>
        </form>
      </template>
    </div>
  </DefaultLayout>
</template>

<script setup lang="ts">
// Módulo Créditos — Configuración y feriados (04-frontend pantalla 7). Los defaults se copian
// a cada crédito nuevo y quedan congelados al activarlo; se guarda solo lo que cambió.
import { computed, onMounted, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import BarraAccionMovil from '@/components/Creditos/BarraAccionMovil.vue'
import FeriadosCredito from '@/components/Creditos/FeriadosCredito.vue'
import { creditoService } from '@/services/admin/creditoService'
import { useCreditosCatalogosStore } from '@/stores/creditosCatalogos'
import { useToast } from '@/composables/useToast'
import { interpretarErrorCredito } from '@/composables/creditos/errorCredito'
import { cambiosConfig, formularioConfig, SECCIONES_CONFIG, type FormConfig } from '@/helpers/creditos/configuracion'

const PESTANAS = [
  { id: 'negocio', texto: 'Valores del negocio' },
  { id: 'feriados', texto: 'Feriados' },
] as const
const DIAS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']

const route = useRoute()
const router = useRouter()
const toast = useToast()
const catalogos = useCreditosCatalogosStore()

const pestana = computed(() => (route.query.tab === 'feriados' ? 'feriados' : 'negocio'))
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
  router.replace({ query: id === 'feriados' ? { tab: 'feriados' } : {} })
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

<style scoped>
.creditos-contenedor {
  max-width: 1440px;
}
.boton-alto {
  min-height: 44px;
}
.boton-dia {
  min-width: 56px;
  min-height: 44px;
}
</style>
