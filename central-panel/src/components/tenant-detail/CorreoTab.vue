<script setup lang="ts">
// Envío de cotizaciones por correo (plan-modulo-envio-correo-cotizacion.md §3.1):
// interruptor de la función (se puede ofrecer como adicional al plan), tope diario y
// dominio de la agencia autenticado en Brevo. Cada cambio queda en la auditoría central.
import { onMounted, reactive, ref } from 'vue';
import httpClient from '@/services/httpClient';

type RegistroDns = { clave: string; tipo: string; nombre: string; valor: string; estado: boolean | null };
type DatosCorreo = {
  habilitado: boolean;
  limite_diario: number;
  brevo_configurado: boolean;
  respaldo: string;
  dominio: {
    dominio: string;
    estado: 'pendiente' | 'verificado' | 'fallido';
    registros: RegistroDns[];
    ultimo_error: string | null;
    verificado_en: string | null;
    ultimo_chequeo_en: string | null;
  } | null;
};

const props = defineProps<{ tenantId: string }>();

const datos = ref<DatosCorreo | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const exito = ref<string | null>(null);
const accion = ref<'' | 'guardar' | 'registrar' | 'verificar' | 'eliminar'>('');
const form = reactive({ habilitado: false, limite_diario: 50 });
const dominioNuevo = ref('');
const copiado = ref<string | null>(null);

const base = () => `central/tenants/${props.tenantId}/correo`;

function aplicar(respuesta: DatosCorreo) {
  datos.value = respuesta;
  form.habilitado = respuesta.habilitado;
  form.limite_diario = respuesta.limite_diario;
}

async function ejecutar(tipo: typeof accion.value, llamada: () => Promise<{ data: DatosCorreo }>, mensaje: string) {
  accion.value = tipo;
  error.value = null;
  exito.value = null;
  try {
    const { data } = await llamada();
    aplicar(data);
    exito.value = mensaje;
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'No se pudo completar la acción.';
  } finally {
    accion.value = '';
  }
}

async function cargar() {
  cargando.value = true;
  try {
    const { data } = await httpClient.get(base());
    aplicar(data);
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar la configuración de correo.';
  } finally {
    cargando.value = false;
  }
}

function guardar() {
  ejecutar('guardar', () => httpClient.put(base(), { ...form }), 'Cambios guardados.');
}

function registrar() {
  ejecutar('registrar', () => httpClient.post(`${base()}/dominio`, { dominio: dominioNuevo.value.trim() }),
    'Dominio registrado en Brevo. Envía a la agencia los registros DNS de abajo.');
}

function verificar() {
  ejecutar('verificar', () => httpClient.post(`${base()}/dominio/verificar`), 'Verificación consultada a Brevo.');
}

function eliminar() {
  if (!confirm('¿Quitar el dominio? Las cotizaciones volverán a salir con la dirección de respaldo.')) return;
  ejecutar('eliminar', () => httpClient.delete(`${base()}/dominio`), 'Dominio quitado.');
}

async function copiar(texto: string, clave: string) {
  try {
    await navigator.clipboard.writeText(texto);
    copiado.value = clave;
    setTimeout(() => { if (copiado.value === clave) copiado.value = null; }, 1500);
  } catch {
    copiado.value = null;
  }
}

const formatoLima = new Intl.DateTimeFormat('es-PE', {
  timeZone: 'America/Lima', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
});
function formatFecha(iso: string | null): string {
  if (!iso) return '—';
  const fecha = new Date(iso);
  return Number.isNaN(fecha.getTime()) ? iso : formatoLima.format(fecha).replace(',', '');
}

function estadoBadge(estado: string): string {
  return estado === 'verificado' ? 'bg-success' : estado === 'fallido' ? 'bg-danger' : 'bg-warning text-dark';
}

onMounted(cargar);
</script>

<template>
  <div>
    <p class="text-muted">
      Envío de cotizaciones por correo desde el cotizador de la agencia. Solo aplica a tenants de
      Agencia de Viajes.
    </p>

    <div v-if="cargando" class="text-muted">Cargando…</div>

    <template v-else-if="datos">
      <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>
      <div v-if="exito" class="alert alert-success py-2">{{ exito }}</div>

      <div class="card mb-3" style="max-width: 560px">
        <div class="card-body">
          <h3 class="h6">Función</h3>
          <div class="form-check form-switch mb-2">
            <input id="correoHabilitado" v-model="form.habilitado" class="form-check-input" type="checkbox" />
            <label class="form-check-label" for="correoHabilitado">Habilitado para este tenant</label>
          </div>
          <label class="form-label small mb-1">Límite de envíos por día</label>
          <input v-model.number="form.limite_diario" type="number" min="1" max="1000" class="form-control mb-2" style="max-width: 160px" />
          <small class="text-muted d-block mb-2">
            La cuota de Brevo es compartida por toda la plataforma (300 por día en el plan gratuito).
          </small>
          <button type="button" class="btn btn-primary" :disabled="accion !== ''" @click="guardar">
            {{ accion === 'guardar' ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>
      </div>

      <div class="card" style="max-width: 860px">
        <div class="card-body">
          <h3 class="h6">Dominio de la agencia en Brevo</h3>

          <div v-if="!datos.brevo_configurado" class="alert alert-warning py-2">
            Falta <code>BREVO_API_KEY</code> en el entorno central: no se puede registrar ni verificar dominios.
          </div>

          <p class="small text-muted">
            Sin dominio verificado, las cotizaciones salen como <strong>{{ datos.respaldo }}</strong> con el nombre de la
            agencia y Reply-To a su correo.
          </p>

          <template v-if="!datos.dominio">
            <div class="input-group" style="max-width: 420px">
              <input v-model="dominioNuevo" type="text" class="form-control" placeholder="agencia.com" />
              <button type="button" class="btn btn-outline-primary" :disabled="accion !== '' || !dominioNuevo.trim() || !datos.brevo_configurado" @click="registrar">
                {{ accion === 'registrar' ? 'Registrando…' : 'Registrar en Brevo' }}
              </button>
            </div>
          </template>

          <template v-else>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
              <strong>{{ datos.dominio.dominio }}</strong>
              <span class="badge" :class="estadoBadge(datos.dominio.estado)">{{ datos.dominio.estado }}</span>
              <small class="text-muted">Último chequeo: {{ formatFecha(datos.dominio.ultimo_chequeo_en) }}</small>
            </div>
            <div v-if="datos.dominio.ultimo_error && datos.dominio.estado !== 'verificado'" class="alert alert-light border py-2 small">
              {{ datos.dominio.ultimo_error }}
            </div>

            <p class="small mb-2">
              Registros DNS para enviar a la agencia (los carga quien administra su DNS). <strong>No crear un segundo
              registro SPF</strong>: si Brevo pide uno, se fusiona con el existente.
            </p>
            <div class="table-responsive mb-3">
              <table class="table table-sm align-middle small">
                <thead>
                  <tr><th>Tipo</th><th>Nombre</th><th>Valor</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                  <tr v-for="registro in datos.dominio.registros" :key="registro.clave">
                    <td>{{ registro.tipo }}</td>
                    <td><code>{{ registro.nombre }}</code></td>
                    <td class="text-break" style="max-width: 360px"><code>{{ registro.valor }}</code></td>
                    <td>
                      <span v-if="registro.estado === true" class="text-success">OK</span>
                      <span v-else-if="registro.estado === false" class="text-muted">Pendiente</span>
                      <span v-else>—</span>
                    </td>
                    <td>
                      <button type="button" class="btn btn-sm btn-outline-secondary" @click="copiar(registro.valor, registro.clave)">
                        {{ copiado === registro.clave ? 'Copiado' : 'Copiar' }}
                      </button>
                    </td>
                  </tr>
                  <tr v-if="datos.dominio.registros.length === 0">
                    <td colspan="5" class="text-muted">Brevo no devolvió registros todavía. Prueba "Verificar ahora".</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <button type="button" class="btn btn-primary me-2" :disabled="accion !== '' || !datos.brevo_configurado" @click="verificar">
              {{ accion === 'verificar' ? 'Verificando…' : 'Verificar ahora' }}
            </button>
            <button type="button" class="btn btn-outline-danger" :disabled="accion !== ''" @click="eliminar">
              {{ accion === 'eliminar' ? 'Quitando…' : 'Quitar dominio' }}
            </button>
          </template>
        </div>
      </div>
    </template>
  </div>
</template>
