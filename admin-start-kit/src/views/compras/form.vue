<template>
    <DefaultLayout>
        <form @submit.prevent="guardar">
            <div v-if="error" class="alert alert-danger py-2" role="alert">{{ error }}</div>
            <div v-if="existente" class="alert alert-warning py-2" role="alert">
                <strong>Ya está registrada:</strong>
                {{ existente.serie }}-{{ existente.numero }} de {{ nombreConCiudad(existente.proveedor ?? '', existente.ciudad) }},
                {{ formatMonto(existente.total, existente.moneda) }}, periodo {{ existente.periodo }}.
                <router-link :to="{ name: 'compras.index', query: { ver: existente.id } }" class="alert-link">Ver la compra</router-link>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="card-title mb-0">{{ compraId ? 'Editar compra' : 'Registrar compra' }}</h4>
                    <router-link :to="{ name: 'compras.index' }" class="btn btn-sm btn-outline-secondary">Volver</router-link>
                </div>
                <div class="card-body">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label small mb-1" for="compra-proveedor">Proveedor *</label>
                            <ProveedorSelector id="compra-proveedor" v-model="proveedor" />
                        </div>
                        <div class="col-md-4">
                            <button v-if="puede('proveedores.gestionar')" type="button" class="btn btn-sm btn-outline-primary"
                                @click="altaRapida = true">
                                <i class="fas fa-bolt me-1"></i>Nuevo proveedor
                            </button>
                        </div>
                        <div v-if="proveedor && !proveedor.numero_documento" class="col-12">
                            <small class="text-warning">Este proveedor no tiene documento.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Comprobante</h5></div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small mb-1" for="compra-tipo">Tipo *</label>
                            <select id="compra-tipo" v-model="form.tipo_comprobante_codigo" class="form-select form-select-sm">
                                <option v-for="t in tipos" :key="t.codigo" :value="t.codigo">{{ t.codigo }} — {{ t.nombre }}</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1" for="compra-serie">Serie *</label>
                            <input id="compra-serie" v-model="form.serie" type="text" class="form-control form-control-sm text-uppercase" maxlength="20">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1" for="compra-numero">Número *</label>
                            <input id="compra-numero" v-model="form.numero" type="text" class="form-control form-control-sm" maxlength="20">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1" for="compra-fecha">Fecha de emisión *</label>
                            <CampoFecha id="compra-fecha" v-model="form.fecha_emision" :max="hoy" etiqueta="emisión" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1" for="compra-periodo">Periodo de anotación</label>
                            <input id="compra-periodo" v-model="form.periodo" type="month" class="form-control form-control-sm"
                                :min="periodoDe(form.fecha_emision ?? '')">
                        </div>
                        <div v-if="pasaDeDoceMeses(form.fecha_emision ?? '', form.periodo)" class="col-12">
                            <small class="text-warning">El periodo pasa de 12 meses después de la emisión: confírmalo con el contador.</small>
                        </div>

                        <div v-if="esNota" class="col-md-8">
                            <label class="form-label small mb-1" for="compra-referencia">Compra que modifica *</label>
                            <select id="compra-referencia" v-model="form.compra_referencia_id" class="form-select form-select-sm">
                                <option :value="null">{{ proveedor ? '— Elegir —' : 'Elige primero el proveedor' }}</option>
                                <option v-for="r in referencias" :key="r.id" :value="r.id">
                                    {{ r.serie }}-{{ r.numero }} · {{ formatFecha(r.fecha_emision) }} · {{ formatMonto(r.total, r.moneda) }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Montos y condición</h5></div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-2">
                            <label class="form-label small mb-1" for="compra-moneda">Moneda *</label>
                            <select id="compra-moneda" v-model="form.moneda" class="form-select form-select-sm">
                                <option value="PEN">Soles (PEN)</option>
                                <option value="USD">Dólares (USD)</option>
                            </select>
                        </div>
                        <div v-if="form.moneda === 'USD'" class="col-md-3">
                            <label class="form-label small mb-1" for="compra-tc">Tipo de cambio *</label>
                            <input id="compra-tc" v-model="form.tipo_cambio" type="number" step="0.0001" min="0" class="form-control form-control-sm">
                            <small class="text-muted">{{ notaTipoCambio }}</small>
                        </div>
                        <div class="w-100"></div>

                        <div v-for="[campo, nombre] in MONTOS" :key="campo" class="col-6 col-md-2">
                            <label class="form-label small mb-1" :for="`compra-${campo}`">{{ nombre }}</label>
                            <input :id="`compra-${campo}`" v-model="form[campo]" type="number" step="0.01" min="0"
                                class="form-control form-control-sm text-end" placeholder="0.00">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1" for="compra-total">Total *</label>
                            <input id="compra-total" v-model="form.total" type="number" step="0.01" min="0"
                                class="form-control form-control-sm text-end fw-semibold" :class="{ 'is-invalid': !cuadra }"
                                @input="totalEscrito = true">
                        </div>
                        <div v-if="!cuadra" class="col-12">
                            <small class="text-danger">El total no cuadra: la suma de los montos es {{ suma }}.</small>
                            <button type="button" class="btn btn-sm btn-link py-0" @click="usarSuma">Usar la suma</button>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small mb-1" for="compra-condicion">Condición *</label>
                            <select id="compra-condicion" v-model="form.condicion" class="form-select form-select-sm">
                                <option value="contado">Contado</option>
                                <option value="credito">Crédito</option>
                            </select>
                        </div>
                        <div v-if="form.condicion === 'credito'" class="col-md-3">
                            <label class="form-label small mb-1" for="compra-vencimiento">Fecha de vencimiento *</label>
                            <CampoFecha id="compra-vencimiento" v-model="form.fecha_vencimiento" :min="form.fecha_emision || undefined"
                                etiqueta="vencimiento" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Adjuntos y observaciones</h5></div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="compra-pdf">PDF (máx. 10 MB)</label>
                            <input id="compra-pdf" type="file" accept=".pdf,application/pdf" class="form-control form-control-sm"
                                @change="elegirArchivo($event, 'adjunto_pdf')">
                            <small v-if="tienePdf" class="text-muted">Ya tiene un PDF; subir otro lo reemplaza.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="compra-xml">XML (máx. 10 MB)</label>
                            <input id="compra-xml" type="file" accept=".xml,text/xml,application/xml" class="form-control form-control-sm"
                                @change="elegirArchivo($event, 'adjunto_xml')">
                            <small v-if="tieneXml" class="text-muted">Ya tiene un XML; subir otro lo reemplaza.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1" for="compra-obs">Observaciones</label>
                            <textarea id="compra-obs" v-model="form.observaciones" rows="2" maxlength="2000"
                                class="form-control form-control-sm"></textarea>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-sm btn-primary" :disabled="guardando || cargando">
                        {{ guardando ? 'Guardando…' : compraId ? 'Guardar cambios' : 'Registrar compra' }}
                    </button>
                </div>
            </div>
        </form>

        <ProveedorAltaRapida v-model="altaRapida" @creado="elegirProveedor" @existente="elegirProveedor" />
        <CompletarDocumentoProveedor v-model="completar" :proveedor-id="proveedor?.id ?? null" :motivo="motivoDocumento"
            @guardado="documentoCompletado" />
    </DefaultLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import CampoFecha from '@/components/CampoFecha.vue';
import CompletarDocumentoProveedor from '@/components/Compras/CompletarDocumentoProveedor.vue';
import ProveedorSelector from '@/components/Compras/ProveedorSelector.vue';
import ProveedorAltaRapida from '@/components/Proveedores/ProveedorAltaRapida.vue';
import httpClient from '@/helpers/http-client';
import { enCentimos, formatMonto, pasaDeDoceMeses, periodoDe, sumaDeMontos, TIPOS_NOTA } from '@/helpers/compras';
import { formatFecha, hoyPeru } from '@/helpers/fecha';
import { nombreConCiudad } from '@/helpers/proveedor';
import { useAuthStore } from '@/stores/auth';
import type { Compra, CompraCatalogos, CompraExistente, CompraProveedor, TipoComprobanteCompra } from '@/types/compras';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const MONTOS = [
    ['base_gravada', 'Base gravada'],
    ['igv', 'IGV'],
    ['exonerado', 'Exonerado'],
    ['inafecto', 'Inafecto'],
    ['otros', 'Otros'],
] as const;

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const puede = (permiso: string) => authStore.isPermitedRoute(permiso);
const compraId = route.params.id ? Number(route.params.id) : null;
const hoy = hoyPeru();

const form = reactive({
    tipo_comprobante_codigo: '01',
    serie: '',
    numero: '',
    fecha_emision: hoy as string | null,
    periodo: periodoDe(hoy),
    moneda: 'PEN' as 'PEN' | 'USD',
    tipo_cambio: '' as string | number,
    base_gravada: '' as string | number,
    igv: '' as string | number,
    exonerado: '' as string | number,
    inafecto: '' as string | number,
    otros: '' as string | number,
    total: '' as string | number,
    condicion: 'contado' as 'contado' | 'credito',
    fecha_vencimiento: '' as string | null,
    compra_referencia_id: null as number | null,
    observaciones: '',
});

const proveedor = ref<CompraProveedor | null>(null);
const tipos = ref<TipoComprobanteCompra[]>([]);
const referencias = ref<Compra[]>([]);
const archivos = reactive<{ adjunto_pdf: File | null; adjunto_xml: File | null }>({ adjunto_pdf: null, adjunto_xml: null });
const tienePdf = ref(false);
const tieneXml = ref(false);

const cargando = ref(true);
const guardando = ref(false);
const error = ref('');
const existente = ref<CompraExistente | null>(null);
const altaRapida = ref(false);
const completar = ref(false);
const motivoDocumento = ref('');
const totalEscrito = ref(false);
const notaTipoCambio = ref('');
let sugerido = '';

const esNota = computed(() => TIPOS_NOTA.includes(form.tipo_comprobante_codigo));
const suma = computed(() => sumaDeMontos(form));
const cuadra = computed(() => form.total === '' || enCentimos(form.total) === enCentimos(suma.value));

// El total sigue a la suma hasta que se escribe a mano (lo que dice el comprobante).
watch(suma, (valor) => {
    if (!totalEscrito.value) form.total = valor;
});
const usarSuma = () => {
    totalEscrito.value = false;
    form.total = suma.value;
};

// El periodo sigue al mes de la emisión.
watch(() => form.fecha_emision, (fecha) => {
    if (!cargando.value && fecha) form.periodo = periodoDe(fecha);
});

const sugerirTipoCambio = async () => {
    notaTipoCambio.value = '';
    if (form.moneda !== 'USD' || !form.fecha_emision) return;
    const fecha = form.fecha_emision;
    try {
        const { data } = await httpClient.get<CompraCatalogos>('compras/catalogos', { params: { fecha } });
        if (fecha !== form.fecha_emision) return;
        if (!data.tipo_cambio) {
            notaTipoCambio.value = 'No hay tipo de cambio SUNAT para esa fecha: escríbelo.';
            return;
        }
        notaTipoCambio.value = `SUNAT venta del ${formatFecha(data.tipo_cambio.fecha)}: ${data.tipo_cambio.venta}`;
        // No pisa lo que escribió la persona.
        if (form.tipo_cambio === '' || String(form.tipo_cambio) === sugerido) {
            sugerido = String(data.tipo_cambio.venta);
            form.tipo_cambio = sugerido;
        }
    } catch {
        notaTipoCambio.value = 'No se pudo consultar el tipo de cambio: escríbelo.';
    }
};
watch(() => [form.moneda, form.fecha_emision], () => {
    if (!cargando.value) sugerirTipoCambio();
});

const cargarReferencias = async () => {
    if (!esNota.value || !proveedor.value) {
        referencias.value = [];
        return;
    }
    const id = proveedor.value.id;
    try {
        const { data } = await httpClient.get('compras', { params: { referencia_para: id, per_page: 100 } });
        if (id !== proveedor.value?.id) return;
        referencias.value = data.compras.filter((c: Compra) => c.id !== compraId);
        if (!referencias.value.some((r) => r.id === form.compra_referencia_id)) form.compra_referencia_id = null;
    } catch (e: any) {
        referencias.value = [];
        error.value = e.response?.data?.message ?? 'No se pudieron cargar las compras del proveedor.';
    }
};
watch(() => [esNota.value, proveedor.value?.id], () => {
    existente.value = null;
    cargarReferencias();
});

const elegirProveedor = (p: { id: number; razon_social: string; ciudad: string | null; numero_documento?: string | null; nombre_comercial?: string | null }) => {
    proveedor.value = {
        id: p.id,
        razon_social: p.razon_social,
        nombre_comercial: p.nombre_comercial ?? null,
        ciudad: p.ciudad,
        numero_documento: p.numero_documento ?? null,
    };
};

const documentoCompletado = (p: CompraProveedor) => {
    elegirProveedor(p);
    error.value = '';
    (Swal as TVueSwalInstance).fire('Listo', 'Documento guardado. Vuelve a guardar la compra.', 'success');
};

const elegirArchivo = (event: Event, campo: 'adjunto_pdf' | 'adjunto_xml') => {
    archivos[campo] = (event.target as HTMLInputElement).files?.[0] ?? null;
};

const guardar = async () => {
    error.value = '';
    existente.value = null;
    if (!proveedor.value) {
        error.value = 'Elige el proveedor.';
        return;
    }

    const datos = new FormData();
    datos.append('proveedor_id', String(proveedor.value.id));
    for (const [campo, valor] of Object.entries(form)) {
        const esMonto = campo === 'total' || MONTOS.some(([m]) => m === campo);
        datos.append(campo, esMonto && valor === '' ? '0' : String(valor ?? ''));
    }
    if (archivos.adjunto_pdf) datos.append('adjunto_pdf', archivos.adjunto_pdf);
    if (archivos.adjunto_xml) datos.append('adjunto_xml', archivos.adjunto_xml);

    guardando.value = true;
    try {
        const { data } = await httpClient.post(compraId ? `compras/${compraId}` : 'compras', datos);
        await (Swal as TVueSwalInstance).fire('Listo', data.message, 'success');
        router.push({ name: 'compras.index' });
    } catch (e: any) {
        const respuesta = e.response?.data;
        if (e.response?.status === 409 && respuesta?.existente) {
            existente.value = respuesta.existente;
        } else if (respuesta?.requiere_documento) {
            error.value = respuesta.message;
            motivoDocumento.value = respuesta.message;
            completar.value = true;
        } else {
            error.value = respuesta?.message ?? 'No se pudo guardar la compra.';
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
        guardando.value = false;
    }
};

const cargarCompra = async () => {
    const { data } = await httpClient.get<{ compra: Compra }>(`compras/${compraId}`);
    const c = data.compra;
    Object.assign(form, {
        tipo_comprobante_codigo: c.tipo_comprobante_codigo,
        serie: c.serie,
        numero: c.numero,
        fecha_emision: c.fecha_emision,
        periodo: c.periodo,
        moneda: c.moneda,
        tipo_cambio: c.tipo_cambio ?? '',
        base_gravada: c.base_gravada,
        igv: c.igv,
        exonerado: c.exonerado,
        inafecto: c.inafecto,
        otros: c.otros,
        total: c.total,
        condicion: c.condicion,
        fecha_vencimiento: c.fecha_vencimiento ?? '',
        compra_referencia_id: c.compra_referencia_id,
        observaciones: c.observaciones ?? '',
    });
    totalEscrito.value = enCentimos(c.total) !== enCentimos(sumaDeMontos(c));
    proveedor.value = c.proveedor;
    tienePdf.value = c.tiene_pdf;
    tieneXml.value = c.tiene_xml;
    if (c.anulada_at) error.value = 'Esta compra está anulada: no se puede editar.';
    else if (c.periodo_cerrado) error.value = `El periodo ${c.periodo} está cerrado: no se puede editar.`;
};

onMounted(async () => {
    try {
        const [{ data }] = await Promise.all([
            httpClient.get<CompraCatalogos>('compras/catalogos'),
            compraId ? cargarCompra() : Promise.resolve(),
        ]);
        tipos.value = data.tipos;
    } catch (e: any) {
        error.value = e.response?.data?.message ?? 'No se pudo cargar el formulario.';
    } finally {
        // Los watch de fecha corren después: no deben pisar lo cargado.
        setTimeout(() => (cargando.value = false));
    }
});
</script>
