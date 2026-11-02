<template>
    <!-- CRM C2 (T-051): un solo modal para las tres operaciones sobre una próxima acción:
         - agendar: tipo, fecha, responsable y descripción.
         - hecha: anotar el resultado como contacto (T-050) y agendar la siguiente; ambos opcionales.
         - reprogramar: otra fecha y, si se quiere, otro responsable. -->
    <Teleport to="body">
        <div v-if="abierto" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="fas me-2" :class="iconoTitulo"></i>{{ titulo }}</h6>
                        <button type="button" class="btn-close" :disabled="guardando" @click="cerrar"></button>
                    </div>
                    <div class="modal-body small">
                        <template v-if="modo === 'hecha'">
                            <div class="form-check mb-2">
                                <input id="accionAnotar" v-model="anotarResultado" class="form-check-input" type="checkbox">
                                <label class="form-check-label" for="accionAnotar">Anotar el resultado en el historial</label>
                            </div>
                            <div v-if="anotarResultado" class="ps-4 mb-3">
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <template v-for="t in TIPOS_CONTACTO" :key="t.valor">
                                        <input :id="`resultado-${t.valor}`" v-model="resultado.tipo" type="radio" class="btn-check" :value="t.valor" autocomplete="off">
                                        <label class="btn btn-outline-primary btn-sm" :for="`resultado-${t.valor}`">{{ t.etiqueta }}</label>
                                    </template>
                                </div>
                                <textarea v-model="resultado.nota" rows="3" maxlength="1000" class="form-control form-control-sm"
                                    placeholder="Qué pasó: lo está pensando, pidió otra fecha…"></textarea>
                            </div>
                            <div class="form-check mb-2">
                                <input id="accionSiguiente" v-model="agendarSiguiente" class="form-check-input" type="checkbox">
                                <label class="form-check-label" for="accionSiguiente">Agendar la siguiente</label>
                            </div>
                        </template>

                        <div v-if="modo !== 'hecha' || agendarSiguiente" class="row g-2" :class="{ 'ps-4': modo === 'hecha' }">
                            <div v-if="modo !== 'reprogramar'" class="col-12">
                                <label class="form-label mb-1">Qué hay que hacer</label>
                                <select v-model="nueva.tipo" class="form-select form-select-sm">
                                    <option v-for="t in opciones?.tipos ?? []" :key="t.valor" :value="t.valor">{{ t.etiqueta }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1">Fecha</label>
                                <CampoFecha v-model="nueva.fecha" :min="hoy" etiqueta="la acción" estatico />
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1">Responsable</label>
                                <select v-model.number="nueva.responsable_id" class="form-select form-select-sm">
                                    <option v-for="r in opciones?.responsables ?? []" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                                </select>
                            </div>
                            <div v-if="modo !== 'reprogramar'" class="col-12">
                                <label class="form-label mb-1">Descripción <span class="text-muted">(opcional)</span></label>
                                <input v-model="nueva.descripcion" type="text" maxlength="500" class="form-control form-control-sm"
                                    placeholder="Por ejemplo: confirmar fechas del viaje">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="guardando" @click="cerrar">Cancelar</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="guardando || !puedeGuardar" @click="guardar">
                            <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-check me-1"></i>{{ textoGuardar }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import CampoFecha from '@/components/CampoFecha.vue';
import { useToast } from '@/composables/useToast';
import { formatDiaCorto, hoyPeru } from '@/helpers/fecha';
import { TIPOS_CONTACTO, type TipoContactoManual } from '@/services/admin/crmActividadService';
import {
    crmAccionService, fechaPropuesta,
    type AccionPendiente, type NuevaAccion, type OpcionesAcciones, type ReferenciaCrm, type TipoAccion,
} from '@/services/admin/crmAccionService';

type Modo = 'agendar' | 'hecha' | 'reprogramar';

const emit = defineEmits<{ (e: 'guardado'): void }>();

const toast = useToast();
const hoy = hoyPeru();

const abierto = ref(false);
const guardando = ref(false);
const modo = ref<Modo>('agendar');
const opciones = ref<OpcionesAcciones | null>(null);
const accion = ref<AccionPendiente | null>(null);
const destino = ref<{ clienteId: number; referenciaTipo: ReferenciaCrm | null; referenciaId: number | null } | null>(null);

const anotarResultado = ref(true);
const agendarSiguiente = ref(false);
const resultado = reactive<{ tipo: TipoContactoManual; nota: string }>({ tipo: 'llamada', nota: '' });
const nueva = reactive<{ tipo: TipoAccion; fecha: string; responsable_id: number; descripcion: string }>({
    tipo: 'llamar', fecha: '', responsable_id: 0, descripcion: '',
});

// Al marcar hecha una llamada o un WhatsApp, el resultado se anota con ese mismo tipo de contacto.
const RESULTADO_POR_TIPO: Partial<Record<TipoAccion, TipoContactoManual>> = { llamar: 'llamada', whatsapp: 'whatsapp' };

const titulo = computed(() => {
    if (modo.value === 'agendar') return 'Agendar acción';
    const a = accion.value;
    const cual = a ? `${a.tipo_etiqueta} (${formatDiaCorto(a.fecha)})` : '';
    return modo.value === 'hecha' ? `Marcar hecha: ${cual}` : `Reprogramar: ${cual}`;
});
const iconoTitulo = computed(() => ({ agendar: 'fa-calendar-plus', hecha: 'fa-check-circle', reprogramar: 'fa-calendar-alt' })[modo.value]);
const textoGuardar = computed(() => ({ agendar: 'Agendar', hecha: 'Marcar hecha', reprogramar: 'Reprogramar' })[modo.value]);

const nuevaValida = computed(() => Boolean(nueva.fecha && nueva.fecha >= hoy && nueva.responsable_id));
const puedeGuardar = computed(() => {
    if (modo.value !== 'hecha') return nuevaValida.value;
    const resultadoValido = !anotarResultado.value || resultado.tipo !== 'nota' || resultado.nota.trim().length > 0;
    return resultadoValido && (!agendarSiguiente.value || nuevaValida.value);
});

const cargarOpciones = async (): Promise<boolean> => {
    try {
        opciones.value = await crmAccionService.opciones();
        return true;
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? 'No se pudieron cargar las opciones de la acción.');
        return false;
    }
};

const reiniciarNueva = (base?: Partial<NuevaAccion>) => {
    nueva.tipo = base?.tipo ?? 'llamar';
    nueva.fecha = base?.fecha ?? fechaPropuesta(opciones.value?.dias_proximo_contacto ?? 2);
    nueva.responsable_id = base?.responsable_id ?? opciones.value?.usuario_id ?? 0;
    nueva.descripcion = base?.descripcion ?? '';
};

const agendar = async (clienteId: number, referenciaTipo: ReferenciaCrm | null = null, referenciaId: number | null = null) => {
    if (!(await cargarOpciones())) return;
    modo.value = 'agendar';
    accion.value = null;
    destino.value = { clienteId, referenciaTipo, referenciaId };
    reiniciarNueva();
    abierto.value = true;
};

const marcarHecha = async (a: AccionPendiente) => {
    if (!(await cargarOpciones())) return;
    modo.value = 'hecha';
    accion.value = a;
    const tipoResultado = RESULTADO_POR_TIPO[a.tipo];
    anotarResultado.value = Boolean(tipoResultado);
    resultado.tipo = tipoResultado ?? 'nota';
    resultado.nota = '';
    agendarSiguiente.value = false;
    reiniciarNueva({ tipo: a.tipo });
    abierto.value = true;
};

const reprogramar = async (a: AccionPendiente) => {
    if (!(await cargarOpciones())) return;
    modo.value = 'reprogramar';
    accion.value = a;
    reiniciarNueva({ tipo: a.tipo, fecha: a.fecha < hoy ? hoy : a.fecha, responsable_id: a.responsable.id });
    abierto.value = true;
};

const cerrar = () => {
    if (!guardando.value) abierto.value = false;
};

const datosNueva = (): NuevaAccion => ({
    tipo: nueva.tipo,
    fecha: nueva.fecha,
    responsable_id: nueva.responsable_id,
    descripcion: nueva.descripcion.trim() || null,
});

const guardar = async () => {
    guardando.value = true;
    try {
        let mensaje = '';
        if (modo.value === 'agendar' && destino.value) {
            mensaje = (await crmAccionService.agendar(destino.value.clienteId, {
                ...datosNueva(),
                referencia_tipo: destino.value.referenciaTipo,
                referencia_id: destino.value.referenciaId,
            })).message;
        } else if (modo.value === 'hecha' && accion.value) {
            mensaje = (await crmAccionService.marcarHecha(accion.value.id, {
                resultado: anotarResultado.value ? { tipo: resultado.tipo, nota: resultado.nota.trim() || null } : null,
                siguiente: agendarSiguiente.value ? datosNueva() : null,
            })).message;
        } else if (modo.value === 'reprogramar' && accion.value) {
            mensaje = (await crmAccionService.reprogramar(accion.value.id, { fecha: nueva.fecha, responsable_id: nueva.responsable_id })).message;
        }
        abierto.value = false;
        toast.success(mensaje);
        emit('guardado');
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? 'No se pudo guardar la acción.');
    } finally {
        guardando.value = false;
    }
};

defineExpose({ agendar, marcarHecha, reprogramar });
</script>
