<template>
    <!-- Sección "Correo" de Configuración de Agencia (plan-modulo-envio-correo-cotizacion.md §3.2).
         Recurso propio (configuracion_correo) con su propio botón de guardar, mismo criterio que
         "Cuentas bancarias" y "Marca del PDF". -->
    <div class="accordion mb-4" id="accCorreo">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accCorreoBody">
                    <span class="badge bg-primary rounded-pill me-2">13</span>
                    <span class="fw-semibold text-dark">Correo de cotizaciones</span>
                    <span v-if="datos" class="badge ms-2" :class="datos.habilitado ? 'bg-success-subtle text-success' : 'bg-light text-muted border'">
                        {{ datos.habilitado ? 'Activo' : 'No contratado' }}
                    </span>
                </button>
            </h2>
            <div id="accCorreoBody" class="accordion-collapse collapse" data-bs-parent="#accCorreo">
                <div class="accordion-body">
                    <div v-if="cargando" class="text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm me-2"></div>Cargando...
                    </div>

                    <template v-else-if="datos">
                        <div v-if="!datos.habilitado" class="alert alert-light border small">
                            El envío de cotizaciones por correo no está activo para tu empresa. Puedes dejar todo
                            configurado; para activarlo, contacta al soporte de UmboSystem.
                        </div>

                        <!-- Estado del remitente -->
                        <div class="alert small py-2" :class="datos.remitente.modo === 'dominio_agencia' ? 'alert-success' : 'alert-warning'">
                            <template v-if="datos.dominio?.estado === 'verificado'">
                                <i class="fas fa-check-circle me-1"></i>
                                Dominio <strong>{{ datos.dominio.dominio }}</strong> verificado.
                                <span v-if="datos.remitente.modo === 'dominio_agencia'">Las cotizaciones salen desde <strong>{{ datos.remitente.email }}</strong>.</span>
                                <span v-else>Completa "Dirección del remitente" para que salgan desde tu dominio.</span>
                            </template>
                            <template v-else-if="datos.dominio">
                                <i class="fas fa-hourglass-half me-1"></i>
                                Dominio <strong>{{ datos.dominio.dominio }}</strong> pendiente de verificación (faltan los registros DNS).
                                Mientras tanto se envía como <strong>{{ datos.respaldo }}</strong> con el nombre de tu agencia.
                            </template>
                            <template v-else>
                                <i class="fas fa-info-circle me-1"></i>
                                Todavía no hay un dominio registrado para tu agencia. Se envía como <strong>{{ datos.respaldo }}</strong>
                                con el nombre de tu agencia, y las respuestas llegan al correo de respuesta de abajo.
                            </template>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Nombre del remitente</label>
                                <input v-model="form.remitente_nombre" type="text" maxlength="120" class="form-control form-control-sm" placeholder="Nombre comercial de la agencia">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Dirección del remitente</label>
                                <div class="input-group input-group-sm">
                                    <input v-model="form.remitente_local" type="text" maxlength="64" class="form-control" placeholder="reservas">
                                    <span class="input-group-text">@{{ datos.dominio?.dominio ?? 'tu-dominio' }}</span>
                                </div>
                                <small class="text-muted">Solo lo que va antes de la @. El dominio lo verifica UmboSystem.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Las respuestas llegan a</label>
                                <input v-model="form.reply_to" type="email" maxlength="190" class="form-control form-control-sm" placeholder="Por defecto, el correo de la empresa">
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mt-4">
                                    <input id="copiaOculta" v-model="form.copia_oculta_activa" class="form-check-input" type="checkbox">
                                    <label class="form-check-label small" for="copiaOculta">Enviarme una copia oculta de cada cotización</label>
                                </div>
                                <input v-if="form.copia_oculta_activa" v-model="form.copia_oculta_email" type="email" maxlength="190"
                                    class="form-control form-control-sm mt-1" placeholder="copia@agencia.com">
                                <small class="text-muted">Así queda constancia en tu buzón (el envío no aparece en "Enviados").</small>
                            </div>

                            <div class="col-12">
                                <label class="form-label small mb-1">Asunto</label>
                                <input v-model="form.asunto_plantilla" type="text" maxlength="255" class="form-control form-control-sm" :placeholder="datos.por_defecto.asunto">
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">Mensaje</label>
                                <textarea v-model="form.cuerpo_plantilla" rows="7" class="form-control form-control-sm" :placeholder="datos.por_defecto.cuerpo"></textarea>
                                <small class="text-muted">
                                    Texto simple. Puedes usar:
                                    <code v-for="variable in datos.variables" :key="variable" class="me-1">{{ '{' + variable + '}' }}</code>
                                    — se reemplazan con los datos de cada cotización. Vacío = mensaje por defecto.
                                </small>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">Firma</label>
                                <textarea v-model="form.firma" rows="3" class="form-control form-control-sm" placeholder="Equipo de reservas · Teléfono · Dirección"></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input id="adjCondDefecto" v-model="form.adjuntar_condiciones_por_defecto" class="form-check-input" type="checkbox">
                                    <label class="form-check-label small" for="adjCondDefecto">Adjuntar las condiciones generales por defecto</label>
                                </div>
                            </div>
                        </div>

                        <div class="border rounded p-3 mt-3 bg-light">
                            <div class="small text-muted mb-2">Vista previa (con datos de ejemplo)</div>
                            <div class="small"><strong>De:</strong> {{ previa.de }}</div>
                            <div class="small"><strong>Asunto:</strong> {{ previa.asunto }}</div>
                            <div class="small mt-2" style="white-space: pre-line">{{ previa.cuerpo }}</div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
                            <span class="small text-muted me-auto align-self-center">Envíos de hoy: {{ datos.enviados_hoy }} de {{ datos.limite_diario }}</span>
                            <button class="btn btn-outline-secondary btn-sm" :disabled="probando || guardando || !datos.habilitado"
                                :title="datos.habilitado ? 'Envía un ejemplo a tu propio correo' : 'La función no está activa'" @click="enviarPrueba">
                                <span v-if="probando" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="fas fa-envelope me-1"></i>Enviarme una prueba
                            </button>
                            <button class="btn btn-primary btn-sm" :disabled="guardando" @click="guardar">
                                <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="fas fa-save me-1"></i>Guardar correo
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useToast } from '@/composables/useToast';
import {
    correoCotizacionService,
    type ConfiguracionCorreo,
    type ConfiguracionCorreoRespuesta,
} from '@/services/admin/correoCotizacionService';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const toast = useToast();
const cargando = ref(true);
const guardando = ref(false);
const probando = ref(false);
const datos = ref<ConfiguracionCorreoRespuesta | null>(null);
const form = reactive<ConfiguracionCorreo>({
    remitente_nombre: null,
    remitente_local: null,
    reply_to: null,
    copia_oculta_activa: false,
    copia_oculta_email: null,
    asunto_plantilla: null,
    cuerpo_plantilla: null,
    firma: null,
    adjuntar_condiciones_por_defecto: true,
});

const EJEMPLO: Record<string, string> = {
    cliente: 'Ana Pérez', codigo: 'CDKM-0001', alternativa: 'Opción 1', destino: 'Tarapoto',
    fecha_viaje: '15/11/2026 al 18/11/2026', vendedor: 'Tu nombre', vigencia: '30/10/2026',
};

const reemplazar = (texto: string): string =>
    texto.replace(/\{(\w+)\}/g, (completo, clave) => {
        if (clave === 'agencia') return form.remitente_nombre || datos.value?.remitente.nombre || '';
        return EJEMPLO[clave] ?? completo;
    });

const previa = computed(() => {
    const d = datos.value;
    if (!d) return { de: '', asunto: '', cuerpo: '' };
    const nombre = form.remitente_nombre || d.remitente.nombre;
    const verificado = d.dominio?.estado === 'verificado' && form.remitente_local;
    const email = verificado ? `${form.remitente_local!.toLowerCase()}@${d.dominio!.dominio}` : d.respaldo;
    let cuerpo = reemplazar(form.cuerpo_plantilla || d.por_defecto.cuerpo);
    if (form.firma) cuerpo += `\n\n${reemplazar(form.firma)}`;
    return {
        de: `${nombre} <${email}>`,
        asunto: reemplazar(form.asunto_plantilla || d.por_defecto.asunto),
        cuerpo,
    };
});

const aplicar = (respuesta: ConfiguracionCorreoRespuesta) => {
    datos.value = respuesta;
    Object.assign(form, respuesta.configuracion_correo);
};

const cargar = async () => {
    cargando.value = true;
    try {
        aplicar(await correoCotizacionService.configuracion());
    } catch {
        datos.value = null;
    } finally {
        cargando.value = false;
    }
};

const guardar = async () => {
    guardando.value = true;
    try {
        aplicar(await correoCotizacionService.guardarConfiguracion({ ...form }));
        toast.success('Configuración del correo guardada');
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('No se pudo guardar', error?.response?.data?.message ?? 'Revisa los datos.', 'warning');
    } finally {
        guardando.value = false;
    }
};

const enviarPrueba = async () => {
    probando.value = true;
    try {
        const respuesta = await correoCotizacionService.enviarPrueba();
        toast.success(respuesta.message);
        await cargar();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('No se envió la prueba', error?.response?.data?.message ?? 'Intenta de nuevo.', 'warning');
    } finally {
        probando.value = false;
    }
};

onMounted(cargar);
</script>
