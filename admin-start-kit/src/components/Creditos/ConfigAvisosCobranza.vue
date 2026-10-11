<template>
  <div v-if="cargando" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
  <div v-else-if="errorCarga" class="alert alert-danger">{{ errorCarga }}</div>

  <form v-else-if="form" novalidate @submit.prevent="guardar">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white border-bottom py-2">
        <span class="fw-semibold text-dark">Aviso diario de cobranza</span>
        <small class="text-muted d-block">
          Cada mañana, cada persona que cobra recibe por correo lo que tiene que cobrar ese día: lo que vence y lo atrasado.
        </small>
      </div>
      <div class="card-body py-3">
        <div class="row g-3">
          <div class="col-12">
            <div class="form-check form-switch mb-0">
              <input id="avisos-activo" v-model="form.activo" class="form-check-input" type="checkbox" role="switch" />
              <label class="form-check-label fw-semibold" for="avisos-activo">
                {{ form.activo ? 'Encendido' : 'Apagado' }}
              </label>
            </div>
          </div>

          <div class="col-12 col-md-4 col-xl-3">
            <label class="form-label mb-1 small fw-semibold text-secondary" for="avisos-hora">Hora de envío (hora de Perú)</label>
            <CampoHora id="avisos-hora" v-model="form.hora" etiqueta="envío" :invalido="!!errores.hora" :disabled="!form.activo" />
            <div v-if="errores.hora" class="invalid-feedback d-block">{{ errores.hora }}</div>
            <small v-else class="text-muted">Sale en los 15 minutos siguientes a esta hora.</small>
          </div>

          <div class="col-12 col-md-8 col-xl-9">
            <span class="form-label mb-1 small fw-semibold text-secondary d-block">Días en que sale</span>
            <div class="d-flex flex-wrap gap-1" role="group" aria-label="Días en que sale el aviso">
              <button v-for="(d, i) in DIAS" :key="d" type="button" class="btn btn-sm" :disabled="!form.activo"
                :aria-pressed="form.dias.includes(i + 1)"
                :class="form.dias.includes(i + 1) ? 'btn-secondary' : 'btn-outline-secondary'" @click="alternarDia(i + 1)">
                {{ d }}
              </button>
            </div>
            <div v-if="errores.dias" class="invalid-feedback d-block">{{ errores.dias }}</div>
            <small v-else class="text-muted">Marcados: ese día llega el aviso.</small>
          </div>

          <div class="col-12 col-md-6">
            <div class="form-check form-switch mb-0">
              <input id="avisos-atrasados" v-model="form.incluirAtrasados" class="form-check-input" type="checkbox" role="switch" :disabled="!form.activo" />
              <label class="form-check-label small" for="avisos-atrasados">Sumar lo atrasado (con su mora) a lo que vence hoy</label>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="form-check form-switch mb-0">
              <input id="avisos-sin-cobros" v-model="form.sinCobros" class="form-check-input" type="checkbox" role="switch" :disabled="!form.activo" />
              <label class="form-check-label small" for="avisos-sin-cobros">Avisar al administrador aunque ese día no haya cobros</label>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white border-bottom py-2 fw-semibold text-dark">Quién lo recibe</div>
      <div class="card-body py-3 small">
        <ul class="mb-2 ps-3">
          <li><strong>Quien ve toda la cartera</strong> (administrador): el total del día y el desglose por cobrador.</li>
          <li><strong>Cobradores y asesores que cobran</strong>: solo sus clientes.</li>
          <li>Con el permiso <em>"Recibir el PDF de la agenda adjunto"</em> (Roles) llega la hoja de ruta en PDF; sin él, un botón que abre la Agenda.</li>
          <li>Cada persona debe guardar y <strong>confirmar su correo</strong> en <em>Mis avisos</em>; sin correo confirmado no recibe nada.</li>
        </ul>
        <router-link :to="{ name: 'creditos.avisos' }" class="btn btn-sm btn-outline-primary">
          <i class="far fa-envelope me-1"></i>Mis avisos: mi correo y enviar prueba
        </router-link>
      </div>
    </div>

    <BarraAccionMovil>
      <button type="button" class="btn btn-outline-secondary" :disabled="!hayCambios || guardando" @click="descartar">
        <i class="fas fa-undo me-2"></i>Descartar
      </button>
      <button type="submit" class="btn btn-primary fw-semibold" :disabled="!hayCambios || guardando">
        <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span><i v-else class="fas fa-save me-2"></i>
        Guardar
      </button>
    </BarraAccionMovil>
  </form>
</template>

<script setup lang="ts">
// Módulo Créditos (Fase 5) — pestaña "Avisos" de la configuración: enciende el aviso diario de
// cobranza y fija hora y días. Guarda solo los campos avisos_* que cambiaron, por el mismo
// PUT creditos/configuracion que el resto de la pantalla.
import { computed, onMounted, ref } from 'vue'
import CampoHora from '@/components/CampoHora.vue'
import BarraAccionMovil from '@/components/Creditos/BarraAccionMovil.vue'
import { creditoService } from '@/services/admin/creditoService'
import { useCreditosCatalogosStore } from '@/stores/creditosCatalogos'
import { useToast } from '@/composables/useToast'
import { interpretarErrorCredito } from '@/composables/creditos/errorCredito'
import { cambiosAvisos, formularioAvisos, type FormAvisos } from '@/helpers/creditos/avisos'

const DIAS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']

const toast = useToast()
const catalogos = useCreditosCatalogosStore()

const original = ref<FormAvisos | null>(null)
const form = ref<FormAvisos | null>(null)
const cargando = ref(true)
const guardando = ref(false)
const errorCarga = ref<string | null>(null)
const errores = ref<Record<string, string>>({})

const hayCambios = computed(() => !!form.value && !!original.value && Object.keys(cambiosAvisos(original.value, form.value)).length > 0)

onMounted(async () => {
  try {
    const config = await creditoService.configuracion()
    original.value = formularioAvisos(config)
    form.value = formularioAvisos(config)
  } catch (e) {
    errorCarga.value = interpretarErrorCredito(e).mensaje
  } finally {
    cargando.value = false
  }
})

function alternarDia(dia: number) {
  if (!form.value) return
  const actuales = form.value.dias
  form.value.dias = actuales.includes(dia) ? actuales.filter((d) => d !== dia) : [...actuales, dia].sort()
}

async function guardar() {
  if (!form.value || !original.value || guardando.value) return
  errores.value = {}
  if (form.value.activo && !/^\d{2}:\d{2}$/.test(form.value.hora)) errores.value.hora = 'Elige la hora de envío.'
  if (form.value.activo && form.value.dias.length === 0) errores.value.dias = 'Marca al menos un día.'
  if (Object.keys(errores.value).length) {
    toast.warning('Revisa los campos marcados.')
    return
  }
  const cambios = cambiosAvisos(original.value, form.value)
  if (!Object.keys(cambios).length) return

  guardando.value = true
  try {
    const guardada = await creditoService.guardarConfiguracion(cambios)
    original.value = formularioAvisos(guardada)
    form.value = formularioAvisos(guardada)
    catalogos.invalidarConfiguracion()
    toast.success(guardada.avisos_activo ? 'Aviso diario guardado y encendido' : 'Aviso diario guardado (apagado)')
  } catch (e) {
    const error = interpretarErrorCredito(e)
    if (error.tipo === 'validacion') {
      errores.value = {
        ...(error.campos.avisos_hora ? { hora: error.campos.avisos_hora } : {}),
        ...(error.campos.avisos_dias ? { dias: error.campos.avisos_dias } : {}),
      }
    }
    toast.warning(error.tipo === 'validacion' ? 'Revisa los campos marcados.' : error.mensaje)
  } finally {
    guardando.value = false
  }
}

function descartar() {
  if (!original.value) return
  form.value = { ...original.value, dias: [...original.value.dias] }
  errores.value = {}
}
</script>
