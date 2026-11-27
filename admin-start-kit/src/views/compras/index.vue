<template>
    <DefaultLayout>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">Comprobantes de compra</h4>
                <router-link v-if="puede('compras.registrar')" :to="{ name: 'compras.nueva' }" class="btn btn-sm btn-success">
                    <i class="far fa-plus-square me-1"></i>Registrar compra
                </router-link>
            </div>

            <div class="card-body">
                <div class="row g-2 mb-3 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="filtro-periodo">Periodo</label>
                        <input id="filtro-periodo" v-model="filtros.periodo" type="month" class="form-control form-control-sm" @change="buscar">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1" for="filtro-proveedor">Proveedor</label>
                        <ProveedorSelector id="filtro-proveedor" v-model="proveedor" placeholder="Todos" @update:model-value="buscar" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="filtro-tipo">Tipo</label>
                        <select id="filtro-tipo" v-model="filtros.tipo" class="form-select form-select-sm" @change="buscar">
                            <option value="">Todos</option>
                            <option v-for="t in tipos" :key="t.codigo" :value="t.codigo">{{ t.codigo }} — {{ t.nombre }}</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1" for="filtro-buscar">Buscar</label>
                        <div class="input-group input-group-sm">
                            <input id="filtro-buscar" v-model="filtros.search" type="text" class="form-control"
                                placeholder="Serie-número o razón social" @keyup.enter="buscar">
                            <button type="button" class="btn btn-primary" title="Buscar" @click="buscar"><i class="fas fa-search"></i></button>
                            <button type="button" class="btn btn-outline-secondary" title="Limpiar filtros" @click="limpiar">
                                <i class="fas fa-eraser"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12 col-md-2">
                        <div class="form-check form-switch">
                            <input id="filtro-anuladas" v-model="filtros.incluir_anuladas" class="form-check-input" type="checkbox" @change="buscar">
                            <label class="form-check-label small" for="filtro-anuladas">Ver anuladas</label>
                        </div>
                    </div>
                </div>

                <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

                <div class="table-responsive">
                    <table class="table table-sm table-centered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Tipo</th>
                                <th>Serie-número</th>
                                <th>Proveedor</th>
                                <th>Moneda</th>
                                <th class="text-end">Total</th>
                                <th>Condición</th>
                                <th>Pago</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in compras" :key="c.id" :class="{ 'text-muted': c.anulada_at }">
                                <td>{{ formatFecha(c.fecha_emision) }}</td>
                                <td :title="c.tipo_nombre">{{ c.tipo_comprobante_codigo }} · {{ c.tipo_nombre }}</td>
                                <td>
                                    {{ c.serie }}-{{ c.numero }}
                                    <span v-if="c.anulada_at" class="badge bg-danger ms-1">Anulada</span>
                                </td>
                                <td>{{ nombreConCiudad(c.proveedor?.razon_social ?? '', c.proveedor?.ciudad) }}</td>
                                <td>{{ c.moneda }}</td>
                                <td class="text-end">{{ formatMonto(c.total, c.moneda) }}</td>
                                <td>
                                    {{ c.condicion === 'credito' ? 'Crédito' : 'Contado' }}
                                    <small v-if="c.fecha_vencimiento" class="d-block"
                                        :class="!c.anulada_at && estaVencida(c.fecha_vencimiento, hoy) ? 'text-danger fw-semibold' : 'text-muted'">
                                        Vence {{ formatFecha(c.fecha_vencimiento) }}
                                    </small>
                                </td>
                                <td>
                                    <template v-if="c.estado_pago">
                                        <span class="badge" :class="ESTADOS_PAGO[c.estado_pago].clase">{{ ESTADOS_PAGO[c.estado_pago].texto }}</span>
                                        <small v-if="esSaldoAFavor(c.saldo)" class="d-block text-muted">
                                            A favor {{ formatMonto(-Number(c.saldo), c.moneda) }}
                                        </small>
                                        <small v-else-if="c.estado_pago !== 'pagado'" class="d-block text-muted">
                                            Saldo {{ formatMonto(c.saldo ?? 0, c.moneda) }}
                                        </small>
                                    </template>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-link p-1" title="Ver" @click="verDetalle(c.id)">
                                        <i class="las la-eye text-secondary fs-20"></i>
                                    </button>
                                    <!-- T-152: también con el periodo cerrado (Q6). -->
                                    <button v-if="puedeAsignar && !c.anulada_at" type="button" class="btn btn-sm btn-link p-1"
                                        title="Asignar" @click="asignar(c.id)">
                                        <i class="las la-sitemap text-secondary fs-20"></i>
                                    </button>
                                    <template v-if="!c.anulada_at && !c.periodo_cerrado">
                                        <router-link v-if="puede('compras.registrar')" :to="{ name: 'compras.editar', params: { id: c.id } }"
                                            class="btn btn-sm btn-link p-1" title="Editar">
                                            <i class="las la-pen text-secondary fs-20"></i>
                                        </router-link>
                                        <button v-if="puede('compras.anular')" type="button" class="btn btn-sm btn-link p-1" title="Anular"
                                            @click="anular(c)">
                                            <i class="las la-ban text-danger fs-20"></i>
                                        </button>
                                    </template>
                                    <i v-else-if="c.periodo_cerrado" class="las la-lock text-muted fs-20 p-1" :title="`Periodo ${c.periodo} cerrado`"></i>
                                </td>
                            </tr>
                            <tr v-if="!compras.length">
                                <td colspan="9" class="text-center text-muted py-4">No hay compras con estos filtros.</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="totales && total" class="table-light">
                            <tr>
                                <th colspan="5" class="text-end">
                                    Total del filtro en soles
                                    <small class="d-block text-muted fw-normal">
                                        Base {{ formatMonto(totales.base_gravada, 'PEN') }} · IGV {{ formatMonto(totales.igv, 'PEN') }}
                                        · las notas de crédito restan, las anuladas no cuentan
                                    </small>
                                </th>
                                <th class="text-end">{{ formatMonto(totales.total, 'PEN') }}</th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <b-pagination v-model="pagina" :total-rows="total" :per-page="porPagina" prev-text="Anterior" next-text="Siguiente"
                    class="mt-2" />
            </div>
        </div>

        <b-modal v-model="modalDetalle" :title="detalle ? `${detalle.tipo_nombre} ${detalle.serie}-${detalle.numero}` : 'Compra'"
            hide-footer centered size="lg">
            <template v-if="detalle">
                <div v-if="detalle.anulada_at" class="alert alert-danger py-2 small">
                    Anulada el {{ formatFechaHora(detalle.anulada_at) }} por {{ detalle.anulada_por ?? '—' }}: {{ detalle.motivo_anulacion }}
                </div>
                <dl class="row small mb-2">
                    <dt class="col-sm-4">Proveedor</dt>
                    <dd class="col-sm-8">
                        {{ nombreConCiudad(detalle.proveedor?.razon_social ?? '', detalle.proveedor?.ciudad) }}
                        <span class="text-muted">· {{ detalle.proveedor_documento ?? 'sin documento' }}</span>
                    </dd>
                    <dt class="col-sm-4">Emisión / periodo</dt>
                    <dd class="col-sm-8">
                        {{ formatFecha(detalle.fecha_emision) }} · {{ detalle.periodo }}{{ detalle.periodo_cerrado ? ' (cerrado)' : '' }}
                    </dd>
                    <dt class="col-sm-4">Condición</dt>
                    <dd class="col-sm-8">
                        {{ detalle.condicion === 'credito' ? `Crédito, vence ${formatFecha(detalle.fecha_vencimiento)}` : 'Contado' }}
                    </dd>
                    <template v-if="detalle.tipo_cambio">
                        <dt class="col-sm-4">Tipo de cambio</dt>
                        <dd class="col-sm-8">{{ detalle.tipo_cambio }}</dd>
                    </template>
                    <template v-for="[campo, nombre] in MONTOS" :key="campo">
                        <dt class="col-sm-4">{{ nombre }}</dt>
                        <dd class="col-sm-8">{{ formatMonto(detalle[campo], detalle.moneda) }}</dd>
                    </template>
                    <template v-if="detalle.referencia">
                        <dt class="col-sm-4">Modifica a</dt>
                        <dd class="col-sm-8">{{ detalle.referencia.serie }}-{{ detalle.referencia.numero }}</dd>
                    </template>
                    <template v-if="detalle.observaciones">
                        <dt class="col-sm-4">Observaciones</dt>
                        <dd class="col-sm-8">{{ detalle.observaciones }}</dd>
                    </template>
                    <dt class="col-sm-4">Registrada por</dt>
                    <dd class="col-sm-8">{{ detalle.registrada_por ?? '—' }} · {{ formatFechaHora(detalle.created_at) }}</dd>
                </dl>
                <div v-if="detalle.lineas?.length" class="table-responsive mb-2">
                    <table class="table table-sm small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Descripción</th>
                                <th class="text-end">Cantidad</th>
                                <th class="text-end">Valor unit.</th>
                                <th class="text-end">IGV</th>
                                <th class="text-end">Total</th>
                                <th v-if="tieneProductos" class="text-end">Costo base (S/)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in detalle.lineas" :key="l.id">
                                <td>
                                    {{ l.descripcion }}
                                    <small class="text-muted d-block">
                                        {{ nombreAfectacion(l.tipo_afectacion_igv) }}{{ l.codigo_proveedor ? ` · cód. ${l.codigo_proveedor}` : '' }}{{
                                            l.producto && l.producto.title !== l.descripcion ? ` · producto: ${l.producto.title}` : '' }}
                                    </small>
                                </td>
                                <td class="text-end">
                                    {{ Number(l.cantidad) }}{{ Number(l.factor) !== 1 ? ` × ${Number(l.factor)}` : '' }} {{ l.unidad_medida }}
                                </td>
                                <td class="text-end">{{ Number(l.valor_unitario) }}</td>
                                <td class="text-end">{{ formatMonto(l.igv, detalle.moneda) }}</td>
                                <td class="text-end">{{ formatMonto(l.total, detalle.moneda) }}</td>
                                <td v-if="tieneProductos" class="text-end">
                                    {{ l.costo_unitario_base === null ? '—' : Number(l.costo_unitario_base) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <small v-if="tieneProductos" class="text-muted">
                        {{ detalle.con_credito_fiscal ? 'El IGV va a crédito fiscal: el costo es sin IGV.' : 'Sin crédito fiscal: el costo incluye el IGV.' }}
                    </small>
                    <div v-if="aviso" class="text-warning small">{{ aviso }}</div>
                </div>
                <!-- T-152: la clave solo llega en Agencia. -->
                <div v-if="detalle.asignaciones" class="mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <h6 class="small fw-semibold mb-0">Reparto</h6>
                        <button v-if="puedeAsignar && !detalle.anulada_at" type="button" class="btn btn-sm btn-outline-primary"
                            @click="asignar(detalle.id)">
                            <i class="las la-sitemap me-1"></i>Asignar
                        </button>
                    </div>
                    <div v-if="!detalle.asignaciones.length" class="small text-muted fst-italic">Sin asignar</div>
                    <table v-else class="table table-sm small mb-0">
                        <tbody>
                            <tr v-for="a in detalle.asignaciones" :key="`${a.destino}:${a.reserva_id ?? a.salida_operativa_id}`">
                                <td>
                                    <router-link v-if="a.reserva_id" :to="`/agencia-viajes/reservas/${a.reserva_id}`">{{ a.titulo }}</router-link>
                                    <router-link v-else-if="a.salida_operativa_id"
                                        :to="{ name: 'agencia.salidas.detalle', params: { id: a.salida_operativa_id } }">{{ a.titulo }}</router-link>
                                    <template v-else>{{ a.titulo }}</template>
                                    <small v-if="a.usuario" class="d-block text-muted">Ingresado por {{ a.usuario }}</small>
                                </td>
                                <td class="text-end">{{ formatMonto(a.monto, detalle.moneda) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <!-- T-136: las anuladas y las notas de crédito no se pagan. -->
                <div v-if="detalle.estado_pago || detalle.aplicaciones_pago?.length" class="mb-2">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
                        <h6 class="small fw-semibold mb-0">
                            Pagos
                            <template v-if="detalle.estado_pago">
                                <span class="badge ms-1" :class="ESTADOS_PAGO[detalle.estado_pago].clase">
                                    {{ ESTADOS_PAGO[detalle.estado_pago].texto }}
                                </span>
                                <span class="text-muted fw-normal ms-1">
                                    {{ esSaldoAFavor(detalle.saldo)
                                        ? `Saldo a favor ${formatMonto(-Number(detalle.saldo), detalle.moneda)}`
                                        : `Saldo ${formatMonto(detalle.saldo ?? 0, detalle.moneda)}` }}
                                </span>
                            </template>
                        </h6>
                        <div v-if="puede('compras.pagar') && porPagar" class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-primary" @click="pagar">Registrar pago</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="aplicarACuenta">
                                Aplicar pago a cuenta
                            </button>
                        </div>
                        <button v-if="puede('compras.pagar') && !detalle.anulada_at && esSaldoAFavor(detalle.saldo)" type="button"
                            class="btn btn-sm btn-outline-primary" @click="reembolsar">Registrar reembolso</button>
                    </div>
                    <div v-if="Number(detalle.por_aprobar) > 0" class="small text-warning">
                        {{ formatMonto(detalle.por_aprobar!, detalle.moneda) }} esperan la aprobación del egreso en Caja.
                    </div>
                    <div v-if="!detalle.aplicaciones_pago?.length" class="small text-muted fst-italic">Sin pagos aplicados</div>
                    <table v-else class="table table-sm small mb-0">
                        <thead v-if="detalle.moneda === 'USD'" class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Origen</th>
                                <th class="text-end">Aplicado</th>
                                <th class="text-end">Dif. cambio</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in detalle.aplicaciones_pago" :key="a.id" :class="{ 'text-muted': a.desaplicada_at }">
                                <td>{{ formatFecha(a.pago.fecha) }}</td>
                                <td>
                                    {{ a.pago.origen === 'caja' ? `Caja · ${a.pago.metodo_pago}` : `${a.pago.cuenta} · op. ${a.pago.numero_operacion}` }}
                                    <span v-if="a.pago.estado === 'pendiente_aprobacion' && !a.desaplicada_at"
                                        class="badge bg-warning text-dark ms-1">Por aprobar</span>
                                    <small v-if="a.desaplicada_at" class="d-block">Desaplicado: {{ a.motivo_desaplicacion }}</small>
                                </td>
                                <td class="text-end" :class="{ 'text-decoration-line-through': a.desaplicada_at }">
                                    {{ formatMonto(a.monto_compra, detalle.moneda) }}
                                    <small v-if="a.pago.moneda !== detalle.moneda" class="d-block text-muted">
                                        {{ formatMonto(a.monto, a.pago.moneda) }} a T.C. {{ Number(a.tipo_cambio) }}
                                    </small>
                                </td>
                                <td v-if="detalle.moneda === 'USD'" class="text-end">
                                    {{ !a.desaplicada_at && a.pago.estado === 'vigente' ? textoDiferencia(a.diferencia_cambio) : '—' }}
                                </td>
                                <td class="text-end">
                                    <button v-if="puede('compras.pagar') && !a.desaplicada_at" type="button"
                                        class="btn btn-sm btn-link p-0" @click="desaplicar(a.id)">Desaplicar</button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="detalle.moneda === 'USD' && detalle.diferencia_cambio_total != null" class="fw-semibold">
                            <tr>
                                <td colspan="3" class="text-end">Diferencia de cambio total</td>
                                <td class="text-end">{{ textoDiferencia(detalle.diferencia_cambio_total) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <ListaReembolsos v-if="detalle.reembolsos?.length" :reembolsos="detalle.reembolsos" @anular="anularReembolso" />
                <div class="d-flex gap-2">
                    <button v-if="detalle.tiene_pdf" type="button" class="btn btn-sm btn-outline-primary" @click="abrirAdjunto('pdf')">
                        <i class="far fa-file-pdf me-1"></i>Ver PDF
                    </button>
                    <button v-if="detalle.tiene_xml" type="button" class="btn btn-sm btn-outline-primary" @click="abrirAdjunto('xml')">
                        <i class="far fa-file-code me-1"></i>Descargar XML
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary ms-auto" @click="modalDetalle = false">Cerrar</button>
                </div>
            </template>
        </b-modal>
        <AsignarCompra :compra-id="compraAsignar" @cerrar="alCerrarAsignar" @guardado="alAsignar" />
        <RegistrarPago v-model="modalPago" :compra="detalle" />
        <AplicarPagoACuenta v-model="modalAplicar" :compra="detalle" />
        <RegistrarReembolso v-model="modalReembolso" :destino="destinoReembolso" />
    </DefaultLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import ProveedorSelector from '@/components/Compras/ProveedorSelector.vue';
import AsignarCompra from '@/components/Compras/AsignarCompra.vue';
import AplicarPagoACuenta from '@/components/Compras/AplicarPagoACuenta.vue';
import ListaReembolsos from '@/components/Compras/ListaReembolsos.vue';
import RegistrarReembolso from '@/components/Compras/RegistrarReembolso.vue';
import RegistrarPago from '@/components/Compras/RegistrarPago.vue';
import { ESTADOS_PAGO, esSaldoAFavor, textoDiferencia } from '@/helpers/pagosProveedor';
import type { DestinoReembolso, Reembolso } from '@/types/pagosProveedor';
import { pedirMotivo } from '@/helpers/pedirMotivo';
import httpClient from '@/helpers/http-client';
import { AFECTACIONES_COMPRA, estaVencida, formatMonto } from '@/helpers/compras';
import { formatFecha, formatFechaHora, hoyPeru } from '@/helpers/fecha';
import { nombreConCiudad } from '@/helpers/proveedor';
import { useAuthStore } from '@/stores/auth';
import type { Compra, CompraProveedor, ComprasListado, MontosCompra, TipoComprobanteCompra } from '@/types/compras';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const MONTOS = [
    ['base_gravada', 'Base gravada'],
    ['igv', 'IGV'],
    ['exonerado', 'Exonerado'],
    ['inafecto', 'Inafecto'],
    ['otros', 'Otros'],
    ['total', 'Total'],
] as const;

const route = useRoute();
const authStore = useAuthStore();
const puede = (permiso: string) => authStore.isPermitedRoute(permiso);
const hoy = hoyPeru();

const vacios = () => ({ periodo: '', tipo: '', search: '', incluir_anuladas: false });
const filtros = reactive(vacios());
const proveedor = ref<CompraProveedor | null>(null);
const tipos = ref<TipoComprobanteCompra[]>([]);

const compras = ref<Compra[]>([]);
const totales = ref<MontosCompra | null>(null);
const total = ref(0);
const porPagina = ref(15);
const pagina = ref(1);
const error = ref('');

const detalle = ref<Compra | null>(null);
const aviso = ref('');
const modalDetalle = ref(false);
// T-162: el costo solo existe en las líneas con producto.
const tieneProductos = computed(() => !!detalle.value?.lineas?.some((l) => l.product_id !== null));
const nombreAfectacion = (codigo: string | null) =>
    AFECTACIONES_COMPRA.find(([c]) => c === codigo)?.[1] ?? 'Sin afectación';

// T-152
const permiteAsignar = ref(false);
const puedeAsignar = computed(() => permiteAsignar.value && puede('compras.registrar'));
const compraAsignar = ref<number | null>(null);

const mensajeDe = (e: any) => e.response?.data?.message ?? 'No se pudo completar la operación.';
const mostrarError = (e: any) => (Swal as TVueSwalInstance).fire('Error', mensajeDe(e), 'error');

const listar = async () => {
    error.value = '';
    try {
        const { data } = await httpClient.get<ComprasListado>('compras', {
            params: {
                page: pagina.value,
                periodo: filtros.periodo || undefined,
                tipo: filtros.tipo || undefined,
                search: filtros.search.trim() || undefined,
                proveedor_id: proveedor.value?.id,
                incluir_anuladas: filtros.incluir_anuladas ? 1 : undefined,
            },
        });
        compras.value = data.compras;
        totales.value = data.totales_pen;
        total.value = data.total;
        porPagina.value = data.paginate;
        permiteAsignar.value = data.permite_asignar;
    } catch (e) {
        compras.value = [];
        totales.value = null;
        total.value = 0;
        error.value = mensajeDe(e);
    }
};

// Cambiar un filtro vuelve a la primera página.
const buscar = () => {
    if (pagina.value === 1) listar();
    else pagina.value = 1;
};

const limpiar = () => {
    Object.assign(filtros, vacios());
    proveedor.value = null;
    buscar();
};

const verDetalle = async (id: number) => {
    try {
        const { data } = await httpClient.get(`compras/${id}`);
        detalle.value = data.compra;
        aviso.value = data.aviso ?? '';
        modalDetalle.value = true;
    } catch (e) {
        mostrarError(e);
    }
};

// Un modal a la vez: se cierra el detalle y se vuelve a abrir al guardar o al
// cancelar, si se había llegado desde él.
const desdeDetalle = ref(false);

const asignar = (id: number) => {
    desdeDetalle.value = modalDetalle.value;
    modalDetalle.value = false;
    compraAsignar.value = id;
};

const alAsignar = (id: number) => {
    compraAsignar.value = null;
    desdeDetalle.value = false;
    verDetalle(id);
};

const alCerrarAsignar = () => {
    const id = compraAsignar.value;
    compraAsignar.value = null;
    if (desdeDetalle.value && id !== null) verDetalle(id);
    desdeDetalle.value = false;
};

// T-136 — un modal a la vez: se cierra la ficha y, al terminar o cancelar, se vuelve a abrir.
const modalPago = ref(false);
const modalAplicar = ref(false);
const porPagar = computed(() => Number(detalle.value?.saldo ?? 0) - Number(detalle.value?.por_aprobar ?? 0) > 0);

const pagar = () => {
    modalDetalle.value = false;
    modalPago.value = true;
};
const aplicarACuenta = () => {
    modalDetalle.value = false;
    modalAplicar.value = true;
};
// T-140
const modalReembolso = ref(false);
const destinoReembolso = ref<DestinoReembolso | null>(null);
const reembolsar = () => {
    const compra = detalle.value!;
    destinoReembolso.value = {
        proveedor_id: compra.proveedor_id,
        compra_id: compra.id,
        moneda: compra.moneda,
        maximo: Math.abs(Number(compra.saldo)).toFixed(2),
        titulo: `Saldo a favor de ${compra.serie}-${compra.numero}`,
    };
    modalDetalle.value = false;
    modalReembolso.value = true;
};
// Al cerrarse cualquiera de los tres (guardado o cancelado) se recargan la ficha y el listado: una vez.
watch([modalPago, modalAplicar, modalReembolso], (ahora, antes) => {
    if (antes.some((estaba, i) => estaba && !ahora[i]) && detalle.value) {
        verDetalle(detalle.value.id);
        listar();
    }
});

const anularReembolso = async (reembolso: Reembolso) => {
    const compraId = detalle.value!.id;
    modalDetalle.value = false;
    const hecho = await pedirMotivo(
        `Anular reembolso de ${formatMonto(reembolso.monto, reembolso.moneda)}`,
        reembolso.origen === 'caja' ? 'No se borra: el ingreso se corrige en tu turno de caja.' : 'No se borra: queda anulado.',
        'Anular',
        (motivo) => httpClient.post(`compras/reembolsos/${reembolso.id}/anular`, { motivo }),
    );
    if (hecho) listar();
    verDetalle(compraId);
};

const desaplicar = async (aplicacionId: number) => {
    const compraId = detalle.value!.id;
    modalDetalle.value = false;
    const hecho = await pedirMotivo(
        'Desaplicar pago',
        'El monto vuelve a quedar a cuenta del proveedor.',
        'Desaplicar',
        (motivo) => httpClient.post(`compras/aplicaciones/${aplicacionId}/desaplicar`, { motivo }),
    );
    if (hecho) listar();
    verDetalle(compraId);
};

const abrirAdjunto = async (archivo: 'pdf' | 'xml') => {
    try {
        const { data } = await httpClient.get(`compras/${detalle.value!.id}/adjunto/${archivo}`);
        window.open(data.url, '_blank');
    } catch (e) {
        mostrarError(e);
    }
};

const anular = async (compra: Compra) => {
    const { isConfirmed } = await (Swal as TVueSwalInstance).fire({
        title: `Anular ${compra.serie}-${compra.numero}`,
        text: 'No se borra: queda anulada con el motivo.',
        input: 'textarea',
        inputPlaceholder: 'Motivo de la anulación',
        inputAttributes: { maxlength: '255' },
        showCancelButton: true,
        confirmButtonText: 'Anular',
        cancelButtonText: 'Cancelar',
        showLoaderOnConfirm: true,
        preConfirm: async (motivo: string) => {
            try {
                await httpClient.post(`compras/${compra.id}/anular`, { motivo });
            } catch (e) {
                (Swal as TVueSwalInstance).showValidationMessage(mensajeDe(e));
                return false;
            }
            return true;
        },
    });
    if (isConfirmed) listar();
};

const cargarTipos = async () => {
    try {
        const { data } = await httpClient.get('compras/catalogos');
        tipos.value = data.tipos;
    } catch {
        // El listado ya muestra el motivo (módulo apagado, sin permiso…).
    }
};

watch(pagina, listar);
// «Ya está registrada» del formulario lleva aquí con ?ver=id.
watch(() => route.query.ver, (id) => id && verDetalle(Number(id)));

onMounted(() => {
    Promise.all([listar(), cargarTipos()]);
    if (route.query.ver) verDetalle(Number(route.query.ver));
});
</script>
