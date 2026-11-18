import { computed, ref, watch, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useToast } from '@/composables/useToast';
import { alternativaService } from '@/services/admin/alternativaService';
import { reservaService } from '@/services/admin/reservaService';
import { tipoCambioAgenciaService } from '@/services/admin/tipoCambioAgenciaService';
import { descargarArchivo, leerMensajeErrorBlob } from '@/helpers/descargarArchivo';
import type { Alternativa, Cotizacion } from '@/types/agencia-viajes';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que las alternativas necesitan del núcleo (y la navegación, que pone la página). */
export interface DependenciasAlternativas {
    cotizacionId: number;
    cotizacion: Ref<Cotizacion | null>;
    alternativaActiva: Readonly<Ref<Alternativa | null>>;
    alternativaActivaId: Ref<number | null>;
    cargarCotizacion: () => Promise<void>;
    navegar: (ruta: string) => void;
}

/**
 * Alternativas de la cotización: nueva, moneda, renombrar, duplicar, PDF, condiciones generales,
 * aceptar (crea la reserva) y eliminar. Crea tres watchers del formulario «+ Nueva», que solo
 * tocan su propio estado.
 */
export function useAlternativas(deps: DependenciasAlternativas) {
    const { cotizacionId, cotizacion, alternativaActiva, alternativaActivaId, cargarCotizacion, navegar } = deps;
    const toast = useToast();

    // El "+ Nueva" y "Duplicar alternativa" comparten el mismo tope.
    const alcanzoLimiteAlternativas = computed(() => (cotizacion.value?.alternativas?.length ?? 0) >= 5);

    // Reserva a la que enlaza la pestaña de una alternativa: la suya si está aceptada, o la que la
    // reemplazó (modificacionAplicada.reserva) si quedó 'reemplazada'.
    const reservaDeAlternativa = (alt: Alternativa) =>
        (alt.estado === 'reemplazada' ? alt.modificacion_aplicada?.reserva : alt.reserva) ?? null;

    // ── Nueva alternativa ─────────────────────────────────────────────────
    const mostrarFormAlternativa = ref(false);
    const formAlternativa = ref({ nombre: '', moneda_cotizacion: 'PEN' as 'PEN' | 'USD', tipo_cambio_origen: 'dia' as 'dia' | 'agencia', tipo_cambio_valor: null as number | null });
    const mostrarCalculadoraTipoCambio = ref(false);

    // Prellena "Valor nuevo" con la sugerencia SUNAT/SBS solo cuando el origen es "Del día" y el
    // usuario no tipeó nada todavía. Nunca sobreescribe un valor tipeado a mano, y el campo sigue
    // siendo editable después de prellenado.
    const sugerenciaTipoCambioSunat = ref<{ valor: number; fecha: string } | null>(null);
    watch(mostrarFormAlternativa, async (abierto) => {
        if (!abierto) return;
        try {
            const res = await tipoCambioAgenciaService.obtenerSugerenciaSunat();
            sugerenciaTipoCambioSunat.value = res.sugerencia ? { valor: Number(res.sugerencia.valor), fecha: res.sugerencia.fecha } : null;
            if (sugerenciaTipoCambioSunat.value && formAlternativa.value.tipo_cambio_origen === 'dia' && !formAlternativa.value.tipo_cambio_valor) {
                formAlternativa.value.tipo_cambio_valor = sugerenciaTipoCambioSunat.value.valor;
            }
        } catch {
            // sin bloquear el form — el usuario igual puede tipear el valor a mano
            sugerenciaTipoCambioSunat.value = null;
        }
    });
    watch(() => formAlternativa.value.tipo_cambio_origen, (origen) => {
        if (origen === 'dia' && sugerenciaTipoCambioSunat.value && !formAlternativa.value.tipo_cambio_valor) {
            formAlternativa.value.tipo_cambio_valor = sugerenciaTipoCambioSunat.value.valor;
        }
    });

    // T-033 — mismo criterio que AlternativaCreacionService::nombreSugerido(): la letra por cantidad,
    // saltando las ya usadas. Si el nombre queda vacío, el backend usa este mismo.
    const nombreSugeridoAlternativa = computed(() => {
        const usados = (cotizacion.value?.alternativas ?? []).map((a) => a.nombre.trim().toLowerCase());
        for (let i = usados.length; i < 26; i++) {
            const nombre = `Alternativa ${String.fromCharCode(65 + i)}`;
            if (!usados.includes(nombre.toLowerCase())) return nombre;
        }
        return `Alternativa ${usados.length + 1}`;
    });
    // T-033 (R7) — errores del form "+ Nueva" junto a cada campo, no en ventana.
    const erroresAlternativa = ref<Record<string, string>>({});
    watch(mostrarFormAlternativa, () => { erroresAlternativa.value = {}; });

    const creandoAlternativa = ref(false);
    const crearAlternativa = async (confirmado = false) => {
        creandoAlternativa.value = true;
        erroresAlternativa.value = {};
        try {
            const res = await alternativaService.crear(cotizacionId, { ...formAlternativa.value, tipo_cambio_confirmado: confirmado });
            mostrarFormAlternativa.value = false;
            formAlternativa.value.nombre = '';
            await cargarCotizacion();
            alternativaActivaId.value = res.alternativa.id;
        } catch (error: any) {
            // El backend pide un segundo paso consciente cuando tipo_cambio_valor cae fuera del
            // rango de sanidad (2.0-6.0 USD/PEN): no bloquea, solo exige confirmar que no es un tipeo.
            if (error.response?.status === 422 && error.response?.data?.requiere_confirmacion) {
                creandoAlternativa.value = false;
                const confirmacion = await (Swal as TVueSwalInstance).fire({
                    icon: 'warning',
                    title: 'Tipo de cambio fuera de lo esperado',
                    text: error.response.data.message,
                    showCancelButton: true,
                    confirmButtonText: 'Crear igual',
                    cancelButtonText: 'Corregir',
                });
                if (confirmacion.isConfirmed) {
                    await crearAlternativa(true);
                }
                return;
            }
            const data = error.response?.data ?? {};
            if (data.errors) {
                erroresAlternativa.value = Object.fromEntries(
                    Object.entries(data.errors as Record<string, string[]>).map(([campo, msgs]) => [campo, msgs[0]])
                );
            } else if (data.requiere_tipo_cambio) {
                erroresAlternativa.value = { tipo_cambio_valor: data.message };
            } else {
                erroresAlternativa.value = { general: data.message ?? 'No se pudo crear' };
            }
        } finally {
            creandoAlternativa.value = false;
        }
    };

    // T-033 — cambiar la moneda de la alternativa activa (solo sin servicios; el backend lo vuelve a
    // comprobar y toma el tipo de cambio del día).
    const cambiandoMoneda = ref(false);
    const cambiarMonedaAlternativa = async (alt: Alternativa, moneda: 'PEN' | 'USD') => {
        if (moneda === alt.moneda_cotizacion) return;
        cambiandoMoneda.value = true;
        try {
            await alternativaService.actualizar(alt.id, { moneda_cotizacion: moneda });
            await cargarCotizacion();
            toast.success(`Moneda cambiada a ${moneda}`);
        } catch (error: any) {
            await cargarCotizacion();
            toast.error(error.response?.data?.message ?? 'No se pudo cambiar la moneda');
        } finally {
            cambiandoMoneda.value = false;
        }
    };

    // PUT alternativas/{id} ya aceptaba 'nombre' (AlternativaController::update()); este es el
    // control para corregirlo una vez creada.
    const renombrarAlternativa = async (alt: Alternativa) => {
        const { value: nuevoNombre } = await (Swal as TVueSwalInstance).fire({
            title: 'Renombrar alternativa',
            input: 'text',
            inputValue: alt.nombre,
            inputValidator: (value: string) => (!value || !value.trim() ? 'Ingresá un nombre.' : undefined),
            showCancelButton: true,
            confirmButtonText: 'Guardar',
            cancelButtonText: 'Cancelar',
        });

        if (!nuevoNombre || !nuevoNombre.trim() || nuevoNombre.trim() === alt.nombre) return;

        try {
            await alternativaService.actualizar(alt.id, { nombre: nuevoNombre.trim() });
            await cargarCotizacion();
            toast.success('Alternativa renombrada');
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo renombrar la alternativa', 'error');
        }
    };

    // Clona la alternativa activa completa (ver AlternativaController::duplicar()) en una
    // alternativa nueva de la misma cotización, y salta a ella.
    const duplicando = ref(false);
    const duplicarAlternativa = async () => {
        if (!alternativaActiva.value) return;
        duplicando.value = true;
        try {
            const res = await alternativaService.duplicar(alternativaActiva.value.id);
            await cargarCotizacion();
            alternativaActivaId.value = res.alternativa.id;
            toast.success('Alternativa duplicada');
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo duplicar la alternativa', 'error');
        } finally {
            duplicando.value = false;
        }
    };

    // Descarga de los 2 PDFs (ver AlternativaController::pdf()/CondicionesGeneralesController::pdf()):
    // el comercial de la alternativa activa y el de condiciones generales (aparte, mismo contenido
    // para toda cotización del tenant).
    const descargandoPdf = ref(false);
    const descargandoCondiciones = ref(false);

    // T-050: devuelve si se descargó (el envío por WhatsApp no sigue sin el PDF).
    const descargarPdfAlternativa = async (): Promise<boolean> => {
        if (!alternativaActiva.value) return false;
        descargandoPdf.value = true;
        try {
            await descargarArchivo(`/alternativas/${alternativaActiva.value.id}/pdf`, `cotizacion-${alternativaActiva.value.nombre}.pdf`);
            return true;
        } catch (error: any) {
            const mensaje = await leerMensajeErrorBlob(error, 'No se pudo generar el PDF');
            (Swal as TVueSwalInstance).fire('No se pudo generar el PDF', mensaje, 'warning');
            return false;
        } finally {
            descargandoPdf.value = false;
        }
    };

    const descargarCondicionesGenerales = async () => {
        descargandoCondiciones.value = true;
        try {
            await descargarArchivo('/condiciones-generales/pdf', 'condiciones-generales.pdf');
        } catch (error: any) {
            toast.error('No se pudo generar el PDF');
        } finally {
            descargandoCondiciones.value = false;
        }
    };

    // Aceptar no solo cambia el estado: dispara la creación real de la Reserva
    // (ReservaController::aceptar()) y redirige a su pantalla de detalle. Es irreversible (crea una
    // Reserva real, descarta las demás alternativas), por eso confirma antes (incidente real del
    // 07-sep-2026: un click sin querer generaba la reserva). T-052: la confirmación es un modal
    // propio (AceptarAlternativaModal) con la pregunta opcional «¿Qué decidió la venta?».
    const marcandoAceptada = ref(false);
    const aceptandoAlternativa = ref(false);
    const marcarAceptada = () => {
        if (!alternativaActiva.value) return;
        aceptandoAlternativa.value = true;
    };

    const confirmarAceptada = async (resultado: { resultado_motivo_id: number | null; resultado_detalle: string | null }) => {
        if (!alternativaActiva.value) return;

        marcandoAceptada.value = true;
        try {
            const res = await reservaService.aceptarAlternativa(alternativaActiva.value.id, undefined, resultado);
            aceptandoAlternativa.value = false;
            if (res.alerta_cupo_excedido) {
                await (Swal as TVueSwalInstance).fire('Cupo excedido', 'La salida de mayorista elegida ya superó su cupo total — la reserva se creó igual, es solo un aviso.', 'warning');
            }
            // T-042: un tour de un día con la salida cancelada (la reserva se crea igual).
            (res.avisos_salida_cancelada ?? []).forEach((aviso: string) => toast.warning(aviso));
            navegar(`/agencia-viajes/reservas/${res.reserva.id}`);
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo aceptar la alternativa', 'error');
        } finally {
            marcandoAceptada.value = false;
        }
    };

    // Confirmación previa (borra los ítems junto con la alternativa) y error visible si el backend
    // rechaza el borrado (p. ej. ya generó una reserva; ver AlternativaController::destroy()).
    const eliminandoAlternativa = ref(false);
    const eliminarAlternativa = async () => {
        if (!alternativaActiva.value) return;

        const tieneItems = (alternativaActiva.value.items?.length ?? 0) > 0;
        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: '¿Eliminar esta alternativa?',
            text: tieneItems ? 'Tiene ítems agregados — se eliminarán junto con la alternativa.' : 'Esta acción no se puede deshacer.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
        });
        if (!confirmacion.isConfirmed) return;

        eliminandoAlternativa.value = true;
        try {
            await alternativaService.eliminar(alternativaActiva.value.id);
            alternativaActivaId.value = null;
            await cargarCotizacion();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar la alternativa', 'error');
        } finally {
            eliminandoAlternativa.value = false;
        }
    };

    return {
        alcanzoLimiteAlternativas, reservaDeAlternativa, mostrarFormAlternativa, formAlternativa,
        mostrarCalculadoraTipoCambio, sugerenciaTipoCambioSunat, nombreSugeridoAlternativa, erroresAlternativa,
        creandoAlternativa, crearAlternativa, cambiandoMoneda, cambiarMonedaAlternativa, renombrarAlternativa,
        duplicando, duplicarAlternativa, descargandoPdf, descargandoCondiciones, descargarPdfAlternativa,
        descargarCondicionesGenerales, marcandoAceptada, aceptandoAlternativa, marcarAceptada, confirmarAceptada,
        eliminandoAlternativa, eliminarAlternativa,
    };
}
