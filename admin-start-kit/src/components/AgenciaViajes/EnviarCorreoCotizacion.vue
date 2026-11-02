<template>
    <!-- Envío de la alternativa por correo al cliente (plan-modulo-envio-correo-cotizacion.md §4).
         T-050: solo el modal; lo abre la opción "Por correo" de EnviarAlternativa.vue, que la muestra
         si `disponible` (permiso + función habilitada para la empresa). El historial de envíos
         pasó a HistorialActividadesCotizacion.vue. -->
    <Teleport to="body">
        <div v-if="modalAbierto" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Enviar cotización por correo — {{ alternativaNombre }}</h6>
                        <button class="btn-close" :disabled="enviando" @click="cerrar"></button>
                    </div>
                    <div v-if="borrador" class="modal-body">
                        <div class="alert py-2 small mb-3" :class="borrador.remitente.modo === 'dominio_agencia' ? 'alert-light border' : 'alert-warning'">
                            <div>
                                Se enviará desde: <strong>{{ borrador.remitente.nombre }} &lt;{{ borrador.remitente.email }}&gt;</strong>
                            </div>
                            <div v-if="borrador.remitente.reply_to">Las respuestas llegan a: {{ borrador.remitente.reply_to }}</div>
                            <div v-if="borrador.remitente.modo === 'plataforma'" class="mt-1">
                                El dominio de tu agencia todavía no está verificado: el correo sale con la dirección de la plataforma
                                y el nombre de tu agencia. Las respuestas del cliente igual te llegan.
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small mb-1">Para <span class="text-danger">*</span></label>
                            <input v-model="form.para" type="text" class="form-control form-control-sm" placeholder="cliente@correo.com, otro@correo.com">
                            <small class="text-muted">Separa varios correos con coma (máximo 5).</small>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">CC (opcional)</label>
                            <input v-model="form.cc" type="text" class="form-control form-control-sm" placeholder="copia@correo.com">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Asunto <span class="text-danger">*</span></label>
                            <input v-model="form.asunto" type="text" maxlength="255" class="form-control form-control-sm">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small mb-1">Mensaje</label>
                            <textarea v-model="form.mensaje" rows="8" class="form-control form-control-sm"></textarea>
                            <small class="text-muted">Texto simple: se envía tal cual lo escribes.</small>
                        </div>

                        <div class="form-check mb-1">
                            <input id="adjCotizacion" v-model="form.adjuntar_cotizacion" class="form-check-input" type="checkbox">
                            <label class="form-check-label small" for="adjCotizacion">Adjuntar la cotización (PDF)</label>
                        </div>
                        <div class="form-check mb-1">
                            <input id="adjCondiciones" v-model="form.adjuntar_condiciones" class="form-check-input" type="checkbox">
                            <label class="form-check-label small" for="adjCondiciones">Adjuntar las condiciones generales (PDF)</label>
                        </div>
                        <div v-if="borrador.puede_marcar_enviada" class="form-check mb-1">
                            <input id="marcarEnviada" v-model="form.marcar_enviada" class="form-check-input" type="checkbox">
                            <label class="form-check-label small" for="marcarEnviada">Marcar la alternativa como enviada</label>
                        </div>

                        <!-- T-051: la misma llamada propuesta del panel «Enviar al cliente». -->
                        <div class="border rounded mt-3">
                            <ProximaAccionPropuesta v-model="proximaAccion" estatico />
                        </div>

                        <div class="text-muted small mt-3">
                            Envíos de hoy: {{ borrador.enviados_hoy }} de {{ borrador.limite_diario }}.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" :disabled="enviando" @click="cerrar">Cancelar</button>
                        <button class="btn btn-primary btn-sm" :disabled="enviando || !puedeConfirmar" @click="enviar">
                            <span v-if="enviando" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-paper-plane me-1"></i>Enviar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { correoCotizacionService, type BorradorEnvio } from '@/services/admin/correoCotizacionService';
import ProximaAccionPropuesta from '@/components/Clientes/ProximaAccionPropuesta.vue';
import type { ProximaAccion } from '@/services/admin/crmAccionService';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{
    alternativaId: number;
    alternativaNombre: string;
}>();

// T-051: la llamada propuesta la decide EnviarAlternativa.vue; acá se ve y se puede cambiar.
const proximaAccion = defineModel<ProximaAccion>('proximaAccion', { default: null });

const emit = defineEmits<{
    (e: 'enviada', estado: string): void;
    // Exitoso o fallido: el historial de la cotización se vuelve a pedir.
    (e: 'intentado'): void;
}>();

const auth = useAuthStore();
const toast = useToast();

const puedeEnviar = computed(() => auth.isPermitedRoute('cotizaciones.enviar_correo'));

// El interruptor por empresa se consulta una sola vez por sesión de la pantalla.
const habilitado = ref(false);
let estadoConsultado = false;
const disponible = computed(() => puedeEnviar.value && habilitado.value);

const modalAbierto = ref(false);
const preparando = ref(false);
const enviando = ref(false);
const borrador = ref<BorradorEnvio | null>(null);
const form = reactive({
    para: '',
    cc: '',
    asunto: '',
    mensaje: '',
    adjuntar_cotizacion: true,
    adjuntar_condiciones: true,
    marcar_enviada: true,
});

const separarCorreos = (texto: string): string[] =>
    texto.split(/[,;\s]+/).map((c) => c.trim()).filter((c) => c.length > 0);

const puedeConfirmar = computed(() =>
    separarCorreos(form.para).length > 0
    && form.asunto.trim().length > 0
    && (form.adjuntar_cotizacion || form.adjuntar_condiciones || form.mensaje.trim().length > 0),
);

const mensajeError = (error: any, fallback: string): string =>
    error?.response?.data?.message ?? fallback;

const consultarEstado = async () => {
    if (estadoConsultado || !puedeEnviar.value) return;
    estadoConsultado = true;
    try {
        habilitado.value = (await correoCotizacionService.estado()).habilitado;
    } catch {
        habilitado.value = false;
    }
};

const abrir = async () => {
    preparando.value = true;
    try {
        const datos = await correoCotizacionService.preparar(props.alternativaId);
        borrador.value = datos;
        form.para = datos.para.join(', ');
        form.cc = '';
        form.asunto = datos.asunto;
        form.mensaje = datos.mensaje;
        form.adjuntar_cotizacion = true;
        form.adjuntar_condiciones = datos.adjuntar_condiciones;
        form.marcar_enviada = datos.puede_marcar_enviada;
        modalAbierto.value = true;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('No se pudo preparar el envío', mensajeError(error, 'Intenta de nuevo.'), 'warning');
    } finally {
        preparando.value = false;
    }
};

const cerrar = () => {
    if (enviando.value) return;
    modalAbierto.value = false;
};

const enviar = async () => {
    enviando.value = true;
    try {
        const resultado = await correoCotizacionService.enviar(props.alternativaId, {
            para: separarCorreos(form.para),
            cc: separarCorreos(form.cc),
            asunto: form.asunto.trim(),
            mensaje: form.mensaje,
            adjuntar_cotizacion: form.adjuntar_cotizacion,
            adjuntar_condiciones: form.adjuntar_condiciones,
            marcar_enviada: borrador.value?.puede_marcar_enviada ? form.marcar_enviada : false,
            proxima_accion: proximaAccion.value,
        });
        modalAbierto.value = false;
        toast.success(resultado.message);
        emit('enviada', resultado.estado_alternativa);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('No se envió el correo', mensajeError(error, 'Intenta de nuevo en unos minutos.'), 'warning');
    } finally {
        enviando.value = false;
        // También un fallo queda en el historial.
        emit('intentado');
    }
};

watch(() => props.alternativaId, consultarEstado, { immediate: true });

defineExpose({ disponible, preparando, abrir });
</script>
