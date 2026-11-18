import { computed, ref, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { formatFecha, partesFechaHoraPeru } from '@/helpers/fecha';
import { useToast } from '@/composables/useToast';
import { cotizacionService, type ModificacionDeCotizacion } from '@/services/admin/cotizacionService';
import type { Alternativa, Cotizacion } from '@/types/agencia-viajes';
import type HistorialActividadesCotizacion from '@/components/AgenciaViajes/HistorialActividadesCotizacion.vue';
import type AccionesPendientes from '@/components/Clientes/AccionesPendientes.vue';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que el resultado de la cotización (perdida, vigencia, envío) necesita del núcleo. */
export interface DependenciasResultado {
    cotizacion: Ref<Cotizacion | null>;
    alternativaActiva: Readonly<Ref<Alternativa | null>>;
    alternativaActivaId: Ref<number | null>;
    modificacionInfo: Ref<ModificacionDeCotizacion | null>;
    cargarCotizacion: () => Promise<void>;
}

/**
 * Resultado de la cotización: cerrar como perdida y reabrir (T-052), vigencia de la alternativa
 * (T-053) y reflejo del envío al cliente (T-050). No crea watchers.
 */
export function useResultadoCotizacion(deps: DependenciasResultado) {
    const { cotizacion, alternativaActiva, alternativaActivaId, modificacionInfo, cargarCotizacion } = deps;
    const toast = useToast();

    // Envío al cliente (EnviarAlternativa.vue, T-050: correo, WhatsApp u otro medio): el backend
    // puede haberla pasado de borrador a enviada; se refleja sin recargar toda la cotización.
    const historialRef = ref<InstanceType<typeof HistorialActividadesCotizacion> | null>(null);
    // T-051: próximas acciones de la cotización (la llamada propuesta al enviar, las agendadas).
    const accionesRef = ref<InstanceType<typeof AccionesPendientes> | null>(null);
    const proximaAccion = computed(() => accionesRef.value?.proxima ?? null);
    // T-052 (CRM C3): «Cerrar como perdida» / «Reabrir». No aplica a una modificación de reserva ni a
    // una cotización ya vendida (aceptada o reemplazada); el backend lo vuelve a revisar.
    const cotizacionPerdida = computed(() => !!cotizacion.value?.perdida_en);
    const puedeCambiarResultado = computed(() => !modificacionInfo.value
        && !(cotizacion.value?.alternativas ?? []).some((a) => ['aceptada', 'reemplazada'].includes(a.estado)));
    const cerrandoComoPerdida = ref(false);
    const reabriendo = ref(false);

    const onResultadoCambiado = async (mensaje: string) => {
        cerrandoComoPerdida.value = false;
        await cargarCotizacion();
        historialRef.value?.recargar();
        accionesRef.value?.recargar();
        toast.success(mensaje);
    };

    const reabrirCotizacion = async () => {
        if (!cotizacion.value) return;
        reabriendo.value = true;
        try {
            await cotizacionService.reabrir(cotizacion.value.id);
            await onResultadoCambiado('Cotización reabierta: las alternativas volvieron a su estado.');
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo reabrir la cotización', 'error');
        } finally {
            reabriendo.value = false;
        }
    };

    const onAlternativaEnviada = (estado: string, fechaVencimiento?: string | null) => {
        const alternativa = cotizacion.value?.alternativas?.find((a: any) => a.id === alternativaActivaId.value);
        if (alternativa && estado) alternativa.estado = estado as typeof alternativa.estado;
        if (alternativa && fechaVencimiento !== undefined) alternativa.fecha_vencimiento = fechaVencimiento;
    };

    // T-053 — «Vigente hasta el 10/11» / «Vencida el 03/11». Extender guarda el inicio del día
    // siguiente en Perú (vigente todo el día elegido): el día que se muestra es el del segundo anterior.
    const vigenciaActiva = computed(() => {
        const vence = alternativaActiva.value?.fecha_vencimiento;
        const instante = vence ? Date.parse(vence) : NaN;
        if (isNaN(instante)) return null;
        const { fecha } = partesFechaHoraPeru(new Date(instante - 1000).toISOString());
        return { dia: formatFecha(fecha).slice(0, 5), vencida: instante <= Date.now() };
    });
    const extendiendoVigencia = ref(false);

    const onVigenciaExtendida = (fechaVencimiento: string, mensaje: string) => {
        extendiendoVigencia.value = false;
        onAlternativaEnviada('enviada', fechaVencimiento);
        historialRef.value?.recargar();
        toast.success(mensaje);
    };

    return {
        historialRef, accionesRef, proximaAccion, cotizacionPerdida, puedeCambiarResultado, cerrandoComoPerdida,
        reabriendo, onResultadoCambiado, reabrirCotizacion, onAlternativaEnviada, vigenciaActiva,
        extendiendoVigencia, onVigenciaExtendida,
    };
}
