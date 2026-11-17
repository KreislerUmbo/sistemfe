<template>
    <!-- T-060 (CRM C5, solo Agencia): todo lo que pasó con el cliente, lo más reciente arriba, 20 por
         vez. «Anotar contacto» (T-050) vuelve a la ficha acá, donde se ve lo anotado. Notas y textos
         siempre con {{ }}, nunca v-html: los escriben los usuarios. -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span class="fw-semibold text-dark"><i class="fas fa-stream me-2 text-primary"></i>Historial del cliente</span>
            <div class="d-flex align-items-center gap-2">
                <select v-model="tipo" class="form-select form-select-sm w-auto" aria-label="Filtrar por tipo" @change="cargar(1)">
                    <option :value="null">Todo</option>
                    <option v-for="t in TIPOS_EVENTO" :key="t.valor" :value="t.valor">{{ t.etiqueta }}</option>
                </select>
                <AnotarContacto v-if="puedeAnotar" :cliente-id="clienteId" @anotado="alAnotar" />
            </div>
        </div>
        <div class="card-body p-0">
            <div v-if="cargando" class="text-muted small p-3"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
            <div v-else-if="errorCarga" class="text-danger small p-3">{{ errorCarga }}</div>
            <p v-else-if="!eventos.length" class="small text-muted p-3 mb-0">Todavía no hay nada{{ tipo ? ' de este tipo' : '' }}.</p>
            <ul v-else class="list-group list-group-flush">
                <li v-for="e in eventos" :key="e.id" class="list-group-item py-2 d-flex gap-3">
                    <div class="text-center flex-shrink-0" style="width:28px">
                        <i :class="[icono(e).clase, 'fa-fw']" :title="icono(e).titulo"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex justify-content-between flex-wrap gap-1">
                            <div class="small">
                                <span class="text-secondary me-1">{{ etiqueta(e) }}</span>
                                <router-link v-if="ruta(e)" :to="ruta(e)!" class="fw-semibold">{{ titulo(e) }}</router-link>
                                <span v-else class="fw-semibold text-dark">{{ titulo(e) }}</span>
                                <span v-if="insignia(e)" class="badge ms-2" :class="insignia(e)!.clase">{{ insignia(e)!.texto }}</span>
                            </div>
                            <small class="text-muted text-nowrap">{{ cuando(e) }}</small>
                        </div>
                        <div v-if="linea2(e)" class="small text-muted">{{ linea2(e) }}</div>
                        <div v-if="e.nota" class="small text-dark text-break" style="white-space: pre-line">{{ e.nota }}</div>
                    </div>
                </li>
            </ul>
            <div v-if="hayMas && !cargando" class="text-center py-2 border-top">
                <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cargandoMas" @click="cargar(pagina + 1)">
                    <span v-if="cargandoMas" class="spinner-border spinner-border-sm me-1"></span>Ver más
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import AnotarContacto from '@/components/Clientes/AnotarContacto.vue';
import { formatDiaCorto, formatFecha, formatFechaHora } from '@/helpers/fecha';
import { formatoMoneda } from '@/helpers/dashboard';
import { fichaClienteAgenciaService, TIPOS_EVENTO, type EventoLineaTiempo, type TipoEvento } from '@/services/admin/fichaClienteAgenciaService';

const props = withDefaults(defineProps<{ clienteId: number; puedeAnotar?: boolean }>(), { puedeAnotar: false });
const emit = defineEmits<{ (e: 'cambio'): void }>();

const tipo = ref<TipoEvento | null>(null);
const eventos = ref<EventoLineaTiempo[]>([]);
const pagina = ref(1);
const hayMas = ref(false);
const cargando = ref(true);
const cargandoMas = ref(false);
const errorCarga = ref<string | null>(null);

// La última petición gana: cambiar el filtro mientras carga «Ver más» no mezcla listas.
let peticion = 0;
const cargar = async (numero: number) => {
    const esta = ++peticion;
    if (numero === 1) cargando.value = true;
    else cargandoMas.value = true;
    errorCarga.value = null;
    try {
        const datos = await fichaClienteAgenciaService.lineaTiempo(props.clienteId, { tipo: tipo.value, pagina: numero });
        if (esta !== peticion) return;
        eventos.value = numero === 1 ? datos.eventos : [...eventos.value, ...datos.eventos];
        pagina.value = numero;
        hayMas.value = datos.hay_mas;
    } catch (e: any) {
        if (esta === peticion) errorCarga.value = e?.response?.data?.message ?? 'No se pudo cargar el historial.';
    } finally {
        if (esta === peticion) {
            cargando.value = false;
            cargandoMas.value = false;
        }
    }
};

onMounted(() => cargar(1));
watch(() => props.clienteId, () => cargar(1));

const alAnotar = () => {
    cargar(1);
    emit('cambio');
};

defineExpose({ recargar: () => cargar(1) });

// ── Presentación ────────────────────────────────────────────────────

const ESTADO_COTIZACION: Record<string, { texto: string; clase: string }> = {
    // Mismo criterio que badgeEstado() de cotizador/index.vue.
    reservada: { texto: 'Reservada', clase: 'bg-success' },
    anulada: { texto: 'Anulada', clase: 'bg-danger' },
    vencida: { texto: 'Vencida', clase: 'bg-warning text-dark' },
    enviada: { texto: 'Enviada', clase: 'bg-info text-dark' },
    descartada: { texto: 'Descartada', clase: 'bg-dark' },
    perdida: { texto: 'Perdida', clase: 'bg-danger-subtle text-danger' },
    borrador: { texto: 'Borrador', clase: 'bg-secondary' },
};

const CLASE_COMPROBANTE: Record<string, string> = {
    '01': 'Factura', '03': 'Boleta', NV: 'Nota de venta', adelanto: 'Adelanto',
    nota_credito: 'Nota de crédito', nota_debito: 'Nota de débito',
};

const TIPO_ACTIVIDAD: Record<string, string> = {
    envio: 'Envío', llamada: 'Llamada', whatsapp: 'WhatsApp', visita: 'Visita', nota: 'Nota', estado: 'Cambio de estado',
};
const CANAL: Record<string, string> = { correo: 'por correo', whatsapp: 'por WhatsApp', otro: 'por otro medio' };

const icono = (e: EventoLineaTiempo): { clase: string; titulo: string } => {
    switch (e.tipo) {
        case 'cotizacion': return { clase: 'fas fa-route text-primary', titulo: 'Cotización' };
        case 'reserva': return { clase: 'fas fa-suitcase text-success', titulo: 'Reserva' };
        case 'comprobante': return { clase: 'fas fa-file-invoice text-secondary', titulo: 'Comprobante' };
        case 'pago': return { clase: 'fas fa-money-bill-wave text-success', titulo: 'Pago' };
        case 'actividad': return { clase: 'fas fa-comment-dots text-info', titulo: 'Contacto' };
        default: return { clase: e.pendiente ? 'far fa-square text-warning' : 'fas fa-check-square text-muted', titulo: 'Acción' };
    }
};

const etiqueta = (e: EventoLineaTiempo): string => {
    switch (e.tipo) {
        case 'cotizacion': return 'Cotización';
        case 'reserva': return e.venta_directa ? 'Venta directa' : 'Reserva';
        case 'comprobante': return CLASE_COMPROBANTE[e.clase ?? ''] ?? 'Comprobante';
        case 'pago': return e.traspaso ? 'Traspaso del cobro' : 'Pago';
        case 'actividad': return TIPO_ACTIVIDAD[e.titulo] ?? 'Contacto';
        default: return e.pendiente ? 'Por hacer' : 'Hecho';
    }
};

const titulo = (e: EventoLineaTiempo): string => {
    if (e.tipo === 'pago') return formatoMoneda(e.monto, e.moneda);
    if (e.tipo === 'actividad') return e.canal ? CANAL[e.canal] ?? '' : '';
    return e.titulo;
};

const insignia = (e: EventoLineaTiempo): { texto: string; clase: string } | null => {
    if (e.tipo === 'cotizacion' && e.estado) return ESTADO_COTIZACION[e.estado] ?? null;
    if (e.tipo === 'reserva') return e.estado === 'cancelada' ? { texto: 'Cancelada', clase: 'bg-danger' } : { texto: 'Activa', clase: 'bg-success' };
    if (e.tipo === 'comprobante' && !e.enviado) return { texto: 'Sin enviar a SUNAT', clase: 'bg-light text-secondary border' };
    if (e.tipo === 'accion' && e.pendiente) return { texto: 'Pendiente', clase: 'bg-warning text-dark' };
    return null;
};

const linea2 = (e: EventoLineaTiempo): string => {
    const partes: (string | null | undefined)[] = [];
    switch (e.tipo) {
        case 'cotizacion':
            partes.push(e.detalle, e.origen ? `Llegó por ${e.origen}` : null);
            if (e.resultado) partes.push(`${e.resultado.tipo === 'ganada' ? 'Ganada' : 'Perdida'}: ${e.resultado.motivo}${e.resultado.detalle ? ` — ${e.resultado.detalle}` : ''}`);
            partes.push(e.usuario);
            break;
        case 'reserva':
            partes.push(e.detalle, e.viaje?.desde ? `Viaje ${formatFecha(e.viaje.desde)}${e.viaje.hasta ? ` – ${formatFecha(e.viaje.hasta)}` : ''}` : null);
            break;
        case 'comprobante':
            partes.push(formatoMoneda(e.monto, e.moneda), e.detalle, e.usuario);
            break;
        case 'pago':
            partes.push(e.titulo, e.detalle ? `de ${e.detalle}` : null, e.nro_operacion ? `Op. ${e.nro_operacion}` : null);
            break;
        default:
            partes.push(e.usuario);
    }
    return partes.filter(Boolean).join(' · ');
};

// Una acción pendiente es para un día (sin hora); lo demás, un instante en hora de Perú.
const cuando = (e: EventoLineaTiempo): string =>
    e.tipo === 'accion' && e.pendiente && e.dia ? `para el ${formatDiaCorto(e.dia)}` : formatFechaHora(e.fecha);

const ruta = (e: EventoLineaTiempo): RouteLocationRaw | null => {
    if (e.enlace?.cotizacion_id) return { name: 'agencia.cotizador.editar', params: { id: e.enlace.cotizacion_id } };
    if (e.enlace?.reserva_id) return { name: 'agencia.reservas.detalle', params: { id: e.enlace.reserva_id } };
    return null;
};
</script>
