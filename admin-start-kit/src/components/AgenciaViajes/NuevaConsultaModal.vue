<template>
    <!-- T-059 (CRM C4): «Nueva consulta». Registra en un paso a alguien que pregunta: usa un cliente
         que ya existe (si se elige una coincidencia) o crea uno sin documento, y crea la cotización
         con su «Alternativa A». Al terminar lleva al editor de la cotización. -->
    <Teleport to="body">
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="fas fa-comment-dots me-2 text-primary"></i>Nueva consulta</h6>
                        <button type="button" class="btn-close" :disabled="guardando" @click="emit('cerrar')"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- Cliente elegido de las coincidencias: no se crea otro. -->
                            <div v-if="clienteElegido" class="col-12">
                                <div class="alert alert-info py-2 small mb-0 d-flex align-items-center justify-content-between gap-2">
                                    <span><i class="fas fa-user-check me-1"></i>Cliente: <strong>{{ clienteElegido.full_name }}</strong><template v-if="clienteElegido.phone"> · {{ clienteElegido.phone }}</template></span>
                                    <button type="button" class="btn btn-sm btn-link py-0" :disabled="guardando" @click="quitarCliente">Es otra persona</button>
                                </div>
                            </div>
                            <template v-else>
                                <div class="col-12 col-md-7">
                                    <label class="form-label mb-1 small fw-semibold text-secondary" for="consulta-nombre">Nombre</label>
                                    <input id="consulta-nombre" ref="campoNombre" v-model="nombre" type="text" maxlength="250" class="form-control form-control-sm"
                                        :class="{ 'is-invalid': errores.nombre }" placeholder="Cómo se llama" autocomplete="off" @input="buscarCoincidencias">
                                    <div v-if="errores.nombre" class="invalid-feedback">{{ errores.nombre }}</div>
                                </div>
                                <div class="col-12 col-md-5">
                                    <label class="form-label mb-1 small fw-semibold text-secondary" for="consulta-telefono">Teléfono <span class="text-muted fw-normal">(opcional)</span></label>
                                    <input id="consulta-telefono" v-model="telefono" type="tel" inputmode="tel" maxlength="25" class="form-control form-control-sm"
                                        :class="{ 'is-invalid': errores.telefono }" placeholder="999 888 777" autocomplete="off" @input="buscarCoincidencias">
                                    <div v-if="errores.telefono" class="invalid-feedback">{{ errores.telefono }}</div>
                                </div>
                                <div v-if="coincidencias.length" class="col-12">
                                    <div class="small text-muted mb-1">¿Ya es cliente?</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button v-for="c in coincidencias" :key="c.id" type="button" class="btn btn-sm btn-outline-primary"
                                            :disabled="guardando" @click="elegirCliente(c)">
                                            ¿Es {{ c.full_name }}<template v-if="c.phone"> · {{ c.phone }}</template>?
                                        </button>
                                    </div>
                                </div>
                                <div v-if="!puedeRegistrarCliente" class="col-12">
                                    <div class="alert alert-warning py-2 small mb-0">No tienes permiso para registrar clientes: elige uno de los que ya existen.</div>
                                </div>
                            </template>

                            <div class="col-12 col-md-6">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Destino de interés</label>
                                <DestinoTreeSelect v-model="destinoId" @update:label="destinoTexto = $event" />
                                <div v-if="errores.destino" class="invalid-feedback d-block">{{ errores.destino }}</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label mb-1 small fw-semibold text-secondary" for="consulta-origen">¿Por dónde llegó? <span class="text-muted fw-normal">(opcional)</span></label>
                                <select id="consulta-origen" v-model="origenContactoId" class="form-select form-select-sm" :class="{ 'is-invalid': errores.origen_contacto_id }">
                                    <option :value="null">Sin indicar</option>
                                    <option v-for="o in origenes" :key="o.id" :value="o.id">{{ o.nombre }}</option>
                                </select>
                                <div v-if="errores.origen_contacto_id" class="invalid-feedback">{{ errores.origen_contacto_id }}</div>
                            </div>

                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Desde <span class="text-muted fw-normal">(tentativo)</span></label>
                                <CampoFecha v-model="fechaDesde" etiqueta="Desde" estatico />
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary">Hasta <span class="text-muted fw-normal">(tentativo)</span></label>
                                <CampoFecha v-model="fechaHasta" etiqueta="Hasta" :min="fechaDesde || undefined" :invalido="!!errores.fecha_viaje_hasta" estatico />
                                <div v-if="errores.fecha_viaje_hasta" class="invalid-feedback d-block">{{ errores.fecha_viaje_hasta }}</div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary" for="consulta-pasajeros">Pasajeros</label>
                                <input id="consulta-pasajeros" v-model.number="pasajeros" type="number" min="1" :max="MAX_PASAJEROS" class="form-control form-control-sm"
                                    :class="{ 'is-invalid': errores.pasajeros }">
                                <div v-if="errores.pasajeros" class="invalid-feedback">{{ errores.pasajeros }}</div>
                                <div v-else class="form-text small">Adultos; las edades se corrigen en el cotizador.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label mb-1 small fw-semibold text-secondary" for="consulta-nota">Nota <span class="text-muted fw-normal">(opcional)</span></label>
                                <textarea id="consulta-nota" v-model="nota" rows="2" :maxlength="NOTA_MAX" class="form-control form-control-sm"
                                    :class="{ 'is-invalid': errores.nota }" placeholder="Qué busca, presupuesto, cómo prefiere que le escriban…"></textarea>
                                <div v-if="errores.nota" class="invalid-feedback">{{ errores.nota }}</div>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input id="consulta-agendar" v-model="agendarEnvio" class="form-check-input" type="checkbox">
                                    <label class="form-check-label small" for="consulta-agendar">Agendar «Enviar cotización» para hoy</label>
                                </div>
                            </div>

                            <!-- Sin tipo de cambio del día (o fuera de rango): se pide acá, igual que en «Nueva cotización». -->
                            <div v-if="pedirTipoCambio" class="col-12 col-md-4">
                                <label class="form-label mb-1 small fw-semibold text-secondary" for="consulta-tc">Tipo de cambio del día</label>
                                <input id="consulta-tc" v-model.number="tipoCambioValor" type="number" step="0.0001" min="0" class="form-control form-control-sm"
                                    :class="{ 'is-invalid': errores.tipo_cambio_valor }" placeholder="Ej. 3.75" @input="tipoCambioConfirmado = false">
                                <div v-if="errores.tipo_cambio_valor" class="invalid-feedback">{{ errores.tipo_cambio_valor }}</div>
                                <div v-if="tipoCambioRequiereConfirmacion" class="form-check mt-1">
                                    <input id="consulta-tc-ok" v-model="tipoCambioConfirmado" class="form-check-input" type="checkbox">
                                    <label class="form-check-label small" for="consulta-tc-ok">El valor es correcto</label>
                                </div>
                            </div>
                        </div>

                        <div v-if="error" class="alert alert-danger small py-2 mt-3 mb-0">{{ error }}</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="guardando" @click="emit('cerrar')">Cancelar</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="guardando || (!clienteElegido && !puedeRegistrarCliente)" @click="guardar">
                            <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-check me-1"></i>Registrar y cotizar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import CampoFecha from '@/components/CampoFecha.vue';
import DestinoTreeSelect from '@/components/AgenciaViajes/DestinoTreeSelect.vue';
import { consultaService, type CoincidenciaCliente } from '@/services/admin/consultaService';
import { crmOrigenContactoService, type OrigenContacto } from '@/services/admin/crmOrigenContactoService';
import { parametrosCoincidencias } from '@/helpers/clientes/coincidencias';
import { useAuthStore } from '@/stores/auth';

// Mismos topes que ConsultaController (pasajeros) y CrmActividad::NOTA_MAX.
const MAX_PASAJEROS = 30;
const NOTA_MAX = 1000;
const CAMPOS_CON_ERROR = new Set(['nombre', 'telefono', 'destino', 'origen_contacto_id', 'fecha_viaje_hasta', 'pasajeros', 'nota', 'tipo_cambio_valor']);

const emit = defineEmits<{ cerrar: [] }>();
const router = useRouter();
const auth = useAuthStore();
const puedeRegistrarCliente = auth.isPermitedRoute('register_client');

const campoNombre = ref<HTMLInputElement | null>(null);
const nombre = ref('');
const telefono = ref('');
const clienteElegido = ref<CoincidenciaCliente | null>(null);
const coincidencias = ref<CoincidenciaCliente[]>([]);
const origenes = ref<OrigenContacto[]>([]);
const origenContactoId = ref<number | null>(null);
const destinoId = ref<number | null>(null);
const destinoTexto = ref('');
const fechaDesde = ref('');
const fechaHasta = ref('');
const pasajeros = ref<number>(1);
const nota = ref('');
const agendarEnvio = ref(false);
const pedirTipoCambio = ref(false);
const tipoCambioValor = ref<number | null>(null);
const tipoCambioRequiereConfirmacion = ref(false);
const tipoCambioConfirmado = ref(false);
const guardando = ref(false);
const error = ref('');
const errores = ref<Record<string, string>>({});

let temporizador: ReturnType<typeof setTimeout> | undefined;
// Solo cuenta la última búsqueda: una respuesta lenta no pisa a una más nueva.
let busqueda = 0;

onMounted(async () => {
    campoNombre.value?.focus();
    try {
        const res = await crmOrigenContactoService.listar({ solo_activos: true });
        origenes.value = res.origenes;
        origenContactoId.value = res.ultimo_del_usuario;
    } catch {
        // Sin la lista el origen queda «Sin indicar»: es opcional.
    }
});

onBeforeUnmount(() => clearTimeout(temporizador));

const buscarCoincidencias = () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(async () => {
        const params = parametrosCoincidencias(telefono.value, nombre.value);
        const actual = ++busqueda;
        if (!params) {
            coincidencias.value = [];
            return;
        }
        try {
            const clientes = await consultaService.coincidencias(params);
            if (actual === busqueda) coincidencias.value = clientes;
        } catch {
            // Sin coincidencias se sigue igual: se crea un cliente nuevo.
        }
    }, 300);
};

const elegirCliente = (c: CoincidenciaCliente) => {
    clienteElegido.value = c;
    coincidencias.value = [];
    errores.value = {};
};

const quitarCliente = () => {
    clienteElegido.value = null;
    buscarCoincidencias();
};

const guardar = async () => {
    const faltan: Record<string, string> = {};
    if (!clienteElegido.value && !nombre.value.trim()) faltan.nombre = 'Escribe el nombre.';
    if (!destinoTexto.value.trim()) faltan.destino = 'Selecciona un destino.';
    if (!(pasajeros.value >= 1 && pasajeros.value <= MAX_PASAJEROS)) faltan.pasajeros = `Entre 1 y ${MAX_PASAJEROS}.`;
    if (pedirTipoCambio.value && !(Number(tipoCambioValor.value) > 0)) faltan.tipo_cambio_valor = 'Escribe el tipo de cambio del día.';
    errores.value = faltan;
    error.value = '';
    if (Object.keys(faltan).length > 0) return;

    guardando.value = true;
    try {
        const res = await consultaService.crear({
            cliente_id: clienteElegido.value?.id ?? null,
            nombre: clienteElegido.value?.full_name ?? nombre.value.trim(),
            telefono: clienteElegido.value ? null : telefono.value.trim() || null,
            origen_contacto_id: origenContactoId.value,
            destino: destinoTexto.value,
            fecha_viaje_desde: fechaDesde.value || null,
            fecha_viaje_hasta: fechaHasta.value || null,
            pasajeros: pasajeros.value,
            nota: nota.value.trim() || null,
            agendar_envio: agendarEnvio.value,
            ...(pedirTipoCambio.value ? { tipo_cambio_valor: Number(tipoCambioValor.value), tipo_cambio_confirmado: tipoCambioConfirmado.value } : {}),
        });
        router.push(`/agencia-viajes/cotizador/${res.cotizacion.id}`);
    } catch (e: any) {
        const data = e.response?.data ?? {};
        if (data.requiere_tipo_cambio) {
            pedirTipoCambio.value = true;
            tipoCambioRequiereConfirmacion.value = !!data.requiere_confirmacion;
            errores.value = { tipo_cambio_valor: data.message };
        } else if (data.errors) {
            errores.value = Object.fromEntries(
                Object.entries(data.errors as Record<string, string[]>).map(([campo, msgs]) => [campo, msgs[0]])
            );
            // Un error sin campo propio en el modal (p. ej. el cliente elegido ya no existe) va abajo.
            if (Object.keys(errores.value).some((campo) => !CAMPOS_CON_ERROR.has(campo))) error.value = data.message;
        } else {
            error.value = e.response?.status === 403
                ? 'No tienes permiso para registrar esta consulta.'
                : data.message ?? 'No se pudo registrar la consulta.';
        }
    } finally {
        guardando.value = false;
    }
};
</script>
