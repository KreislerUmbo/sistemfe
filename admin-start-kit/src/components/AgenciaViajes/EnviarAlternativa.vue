<template>
    <!-- CRM C1 (T-050): "Enviar" la alternativa al cliente. Los tres medios la marcan como enviada
         (si estaba en borrador) y dejan una actividad en el historial del cliente:
         - Por correo: el modal de EnviarCorreoCotizacion.vue.
         - Por WhatsApp: descarga el PDF y abre wa.me con un texto armado (wa.me no adjunta
           archivos: el vendedor adjunta el PDF descargado).
         - "Ya la envié por otro medio": nota opcional. -->
    <template v-if="correoDisponible || puedeMarcar">
        <button class="btn btn-outline-primary btn-sm w-100" :disabled="ocupado" @click="opcionesAbiertas = !opcionesAbiertas">
            <span v-if="ocupado" class="spinner-border spinner-border-sm me-1"></span>
            <i v-else class="fas fa-paper-plane me-1"></i>Enviar al cliente
            <i class="fas ms-1" :class="opcionesAbiertas ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
        </button>
        <div v-if="opcionesAbiertas" class="list-group list-group-flush border rounded small">
            <!-- T-051: la llamada que se agenda al enviar, por cualquiera de los tres medios. -->
            <ProximaAccionPropuesta v-model="proximaAccion" />
            <button v-if="correoDisponible" type="button" class="list-group-item list-group-item-action py-2" :disabled="ocupado" @click="porCorreo">
                <i class="fas fa-envelope me-2 text-primary"></i>Por correo
            </button>
            <template v-if="puedeMarcar">
                <button type="button" class="list-group-item list-group-item-action py-2" :disabled="ocupado" @click="porWhatsapp">
                    <i class="fab fa-whatsapp me-2 text-success"></i>Por WhatsApp
                    <span v-if="!telefonoCliente" class="d-block text-muted">El cliente no tiene teléfono: eliges el contacto en WhatsApp.</span>
                </button>
                <button type="button" class="list-group-item list-group-item-action py-2" :disabled="ocupado" @click="abrirOtroMedio">
                    <i class="fas fa-check me-2 text-secondary"></i>Ya la envié por otro medio
                </button>
            </template>
        </div>
    </template>

    <EnviarCorreoCotizacion ref="correoRef" v-model:proxima-accion="proximaAccion" :alternativa-id="alternativa.id"
        :alternativa-nombre="alternativa.nombre" @enviada="alEnviarPorCorreo" @intentado="emit('registrada')" />

    <Teleport to="body">
        <div v-if="otroMedioAbierto" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="fas fa-check me-2"></i>Ya la envié por otro medio — {{ alternativa.nombre }}</h6>
                        <button type="button" class="btn-close" :disabled="marcando" @click="otroMedioAbierto = false"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label small mb-1">Nota <span class="text-muted">(opcional)</span></label>
                        <textarea v-model="notaOtroMedio" rows="3" maxlength="1000" class="form-control form-control-sm"
                            placeholder="Por ejemplo: se la entregué impresa en la oficina."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="marcando" @click="otroMedioAbierto = false">Cancelar</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="marcando" @click="confirmarOtroMedio">
                            <span v-if="marcando" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-check me-1"></i>Marcar como enviada
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import EnviarCorreoCotizacion from '@/components/AgenciaViajes/EnviarCorreoCotizacion.vue';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { formatFechaHora } from '@/helpers/fecha';
import { enlaceWhatsappConTexto } from '@/helpers/creditos/documentos';
import { crmActividadService } from '@/services/admin/crmActividadService';
import { crmAccionService, fechaPropuesta, type ProximaAccion } from '@/services/admin/crmAccionService';
import ProximaAccionPropuesta from '@/components/Clientes/ProximaAccionPropuesta.vue';
import type { Alternativa } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = defineProps<{
    alternativa: Alternativa;
    cliente: { full_name: string; phone?: string | null } | null;
    destino: string;
    total: number;
    /** Descarga el PDF de la alternativa; false si no se pudo (el padre ya avisó por qué). */
    descargarPdf: () => Promise<boolean>;
    /** T-051: la cotización ya tiene una acción pendiente; al reenviar no se propone otra. */
    hayAccionesPendientes?: boolean;
}>();

const emit = defineEmits<{
    (e: 'enviada', estado: string, fechaVencimiento?: string | null): void;
    // Algo nuevo para el historial (también un correo fallido).
    (e: 'registrada'): void;
}>();

const auth = useAuthStore();
const toast = useToast();

const correoRef = ref<InstanceType<typeof EnviarCorreoCotizacion> | null>(null);
const correoDisponible = computed(() => Boolean(correoRef.value?.disponible));

// WhatsApp y otro medio = editar la cotización; una aceptada, descartada o reemplazada ya no se envía.
const puedeMarcar = computed(() =>
    auth.isPermitedRoute('cotizaciones.editar') && ['borrador', 'enviada'].includes(props.alternativa.estado));

const telefonoCliente = computed(() => props.cliente?.phone?.trim() || null);

const opcionesAbiertas = ref(false);
const marcando = ref(false);
const otroMedioAbierto = ref(false);
const notaOtroMedio = ref('');
const ocupado = computed(() => marcando.value || Boolean(correoRef.value?.preparando));

// T-051: "Llamar en N días" a nombre de quien envía. Se arma al abrir el panel y se conserva lo
// que el vendedor cambió hasta que el envío sale; después, el próximo envío vuelve a proponer.
const proximaAccion = ref<ProximaAccion>(null);
let propuestaArmada = false;

const armarPropuesta = async () => {
    if (propuestaArmada) return;
    propuestaArmada = true;
    if (props.hayAccionesPendientes) {
        proximaAccion.value = null;
        return;
    }
    try {
        const opciones = await crmAccionService.opciones();
        proximaAccion.value = { fecha: fechaPropuesta(opciones.dias_proximo_contacto), responsable_id: opciones.usuario_id };
    } catch {
        // Sin opciones no se sabe a nombre de quién: se envía sin proponer (no es obligatoria).
        proximaAccion.value = null;
    }
};

const olvidarPropuesta = () => {
    propuestaArmada = false;
    proximaAccion.value = null;
};

watch(opcionesAbiertas, (abierto) => { if (abierto) armarPropuesta(); });
watch(() => props.alternativa.id, () => {
    opcionesAbiertas.value = false;
    olvidarPropuesta();
});

const mensajeError = (error: any, fallback: string): string => error?.response?.data?.message ?? fallback;

const marcarEnviada = async (canal: 'whatsapp' | 'otro', nota?: string) => {
    const resultado = await crmActividadService.marcarEnviada(props.alternativa.id, {
        canal, nota: nota || null, proxima_accion: proximaAccion.value,
    });
    olvidarPropuesta();
    emit('enviada', resultado.estado_alternativa, resultado.fecha_vencimiento);
    emit('registrada');
    return resultado;
};

const alEnviarPorCorreo = (estado: string) => {
    olvidarPropuesta();
    emit('enviada', estado);
};

const porCorreo = () => {
    opcionesAbiertas.value = false;
    correoRef.value?.abrir();
};

const textoWhatsapp = (fechaVencimiento: string | null): string => {
    const nombre = props.cliente?.full_name?.trim().split(/\s+/)[0];
    const simbolo = props.alternativa.moneda_cotizacion === 'USD' ? 'US$' : 'S/';
    const total = props.total.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const lineas = [
        nombre ? `Hola ${nombre}:` : 'Hola:',
        `te envío la cotización de tu viaje${props.destino ? ` a ${props.destino}` : ''} (${props.alternativa.nombre}).`,
        `Total: ${simbolo} ${total}.`,
    ];
    if (fechaVencimiento) lineas.push(`Vigente hasta el ${formatFechaHora(fechaVencimiento).slice(0, 10)}.`);
    lineas.push('Te adjunto el PDF con el detalle.');
    return lineas.join('\n');
};

const porWhatsapp = async () => {
    opcionesAbiertas.value = false;
    // La pestaña se abre en el clic: después de un await el navegador la bloquearía.
    const ventana = window.open('', '_blank');
    if (ventana) ventana.opener = null;
    marcando.value = true;
    try {
        if (!(await props.descargarPdf())) {
            ventana?.close();
            return;
        }
        const resultado = await marcarEnviada('whatsapp');
        const enlace = enlaceWhatsappConTexto(telefonoCliente.value, textoWhatsapp(resultado.fecha_vencimiento));
        if (ventana) {
            ventana.location.href = enlace;
            toast.success('PDF descargado: adjúntalo en el chat de WhatsApp.');
            return;
        }
        // El navegador bloqueó la pestaña: otro window.open después del await también se
        // bloquearía. Se abre con el clic del vendedor en el aviso.
        const respuesta = await (Swal as TVueSwalInstance).fire({
            title: 'PDF descargado',
            text: 'El navegador bloqueó la pestaña de WhatsApp. Ábrela desde acá y adjunta el PDF en el chat.',
            icon: 'info',
            confirmButtonText: 'Abrir WhatsApp',
            showCancelButton: true,
            cancelButtonText: 'Cerrar',
        });
        if (respuesta.isConfirmed) window.open(enlace, '_blank', 'noopener');
    } catch (error: any) {
        ventana?.close();
        (Swal as TVueSwalInstance).fire('No se pudo enviar por WhatsApp', mensajeError(error, 'Intenta de nuevo.'), 'warning');
    } finally {
        marcando.value = false;
    }
};

const abrirOtroMedio = () => {
    opcionesAbiertas.value = false;
    notaOtroMedio.value = '';
    otroMedioAbierto.value = true;
};

const confirmarOtroMedio = async () => {
    marcando.value = true;
    try {
        const resultado = await marcarEnviada('otro', notaOtroMedio.value.trim());
        otroMedioAbierto.value = false;
        toast.success(resultado.message);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('No se pudo marcar como enviada', mensajeError(error, 'Intenta de nuevo.'), 'warning');
    } finally {
        marcando.value = false;
    }
};
</script>
