<template>
    <DefaultLayout>

        <!-- ═══════════════════════════════════════════════════
             CABECERA — estado de la venta + «Datos de la venta» (T-106)
        ═══════════════════════════════════════════════════ -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h5 class="fw-bold mb-0 text-dark me-2">
                <i class="fas fa-cash-register me-2 text-primary"></i>Nueva venta
            </h5>
            <span v-if="cajaAbierta === true" class="chip text-success" title="Sesión de caja">
                <i class="fas fa-circle small me-1"></i>Caja abierta<template v-if="cajaDesde"> desde <b>{{ cajaDesde }}</b></template>
            </span>
            <span v-else-if="cajaAbierta === false" class="chip chip-alerta"><i class="fas fa-circle small me-1"></i>Caja cerrada</span>
            <button type="button" class="chip" @click="datosAbiertos = true">Fecha <b>{{ formatFecha(today) }}</b></button>
            <button type="button" class="chip" :class="{ 'chip-alerta': currency !== 'PEN' }" @click="datosAbiertos = true">
                Moneda <b>{{ currency === 'PEN' ? 'Soles' : 'Dólares' }}</b>
            </button>
            <button v-if="puedeCambiarSucursal && nombreSucursal" type="button" class="chip" @click="datosAbiertos = true">
                Sucursal <b>{{ nombreSucursal }}</b>
            </button>
            <button v-if="tipoEsFiscal && condicion_especial !== 'exportacion'" type="button" class="chip" @click="datosAbiertos = true">
                Destino <b>{{ destino === 'amazonia' ? 'Amazonía' : 'Fuera' }}</b>
            </button>
            <button v-if="etiquetaCondicion" type="button" class="chip chip-alerta" @click="datosAbiertos = true">{{ etiquetaCondicion }}</button>
            <button type="button" class="btn btn-link btn-sm fw-semibold" @click="datosAbiertos = !datosAbiertos">
                Datos de la venta
                <span v-if="noHabituales" class="badge bg-warning-subtle text-warning ms-1">{{ noHabituales }} no habitual{{ noHabituales > 1 ? 'es' : '' }}</span>
                <i class="fas ms-1" :class="datosAbiertos ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
            </button>
            <button type="button" class="btn btn-link btn-sm text-secondary ms-auto" @click="router.push({ name: 'sale.list' })">
                Ver ventas →
            </button>
        </div>

        <DatosVentaPanel v-if="datosAbiertos" v-model:moneda="currency" v-model:fecha="today"
            v-model:sucursal-id="branch_id_seleccionado" v-model:destino="destino" v-model:condicion="condicion_especial"
            v-model:codigo-detraccion="codigo_detraccion_sel" :puede-cambiar-sucursal="puedeCambiarSucursal"
            :sucursales="branches" :es-fiscal="tipoEsFiscal" :codigos-detraccion="codigos_detraccion"
            @detraccion-cambio="onDetractionCodeChange" @listo="cerrarDatos" />

        <!-- Caja cerrada: se puede armar la venta, pero para cobrar hay que abrirla -->
        <div v-if="cajaAbierta === false"
            class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
            <span><b>Tu caja está cerrada.</b> Puedes armar la venta, pero para cobrar al contado tienes que abrirla.</span>
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="abrirCaja">Abrir caja</button>
        </div>

        <div class="grid-venta">
            <!-- ═════════ IZQUIERDA: productos ═════════ -->
            <div class="card border-0 shadow-sm">
                <div class="p-3 border-bottom">
                    <BuscadorProductosVenta ref="buscador" :moneda="currency" @agregar="agregarProducto"
                        @crear="openQuickProductModal" />
                </div>
                <TablaLineasVenta :items="sale_details" :moneda="currency" :afe-defecto="resolverTipAfeIgv"
                    :cliente="client_selected" :puede-cambiar-precio="puedeCambiarPrecio"
                    :resaltado="lineaResaltada" @cambio="alCambiarLinea"
                    @quitar="removeItem" @elegir-precio="elegirPrecioLinea" />
                <div class="p-3 border-top">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="venta-obs">Observaciones</label>
                    <textarea id="venta-obs" v-model="description" rows="2" class="form-control form-control-sm"
                        placeholder="Notas internas que no salen en el comprobante (opcional)"></textarea>
                </div>
            </div>

            <!-- ═════════ DERECHA: cobro ═════════ -->
            <div class="panel-cobro">
                <div class="card border-0 shadow-sm">
                    <div class="p-3 border-bottom">
                        <PanelClienteComprobante :cliente="client_selected" v-model:direccion-id="client_address_id"
                            v-model:tipo="tipo_comprobante_codigo" :tipos="tiposComprobanteVisibles"
                            :serie="serieResuelta" :error-serie="errorSeriePreview" @seleccionar="selectClient"
                            @limpiar="clearClientSelection" @registrar="openQuickClientModalWithData" />
                    </div>

                    <div class="p-3 border-bottom">
                        <TotalesVenta v-model:descuento-global="discount_global" :filas="filasTotales"
                            :total="totalAPagar" :simbolo="simbolo" :etiqueta-total="etiquetaTotal"
                            :puede-descuento-global="puedeCambiarPrecio" />
                    </div>

                    <div v-if="condicion_especial === 'anticipo'" class="p-3 border-bottom">
                        <AdelantosVenta :adelantos="adelantosDisponiblesFiltrados" :total-del-cliente="availableAdvances.length"
                            :hay-cliente="!!client_selected" :cargando="loadingAdvances" :simbolo="simbolo"
                            :excede="adelantosExceden" />
                    </div>

                    <div class="p-3 border-bottom d-flex flex-column gap-2">
                        <div class="btn-group btn-group-sm w-100" role="group">
                            <button type="button" class="btn" :class="type_payment == 1 ? 'btn-success' : 'btn-outline-secondary'"
                                @click="type_payment = 1">Contado</button>
                            <button type="button" class="btn" :class="type_payment == 2 ? 'btn-success' : 'btn-outline-secondary'"
                                @click="type_payment = 2">Crédito</button>
                        </div>
                        <CobroContado v-if="type_payment == 1" v-model:pagos="sale_payments" :metodos="paymentMethods"
                            :total-a-pagar="totalAPagar" :simbolo="simbolo" />
                        <CobroCredito v-else v-model:pagos="sale_payments" v-model:cronograma="cronograma"
                            v-model:num-cuotas="num_cuotas" v-model:periodicidad="periodicidad"
                            v-model:aplica-mora="aplica_mora" v-model:tasa-mora="tasa_mora" v-model:tipo-mora="tipo_mora"
                            :metodos="paymentMethods" :moneda="currency" :fecha-base="today"
                            :monto-a-financiar="montoAFinanciar()" :total-a-pagar="totalAPagar" />
                    </div>

                    <div class="p-3 d-flex flex-column gap-1">
                        <button type="button" class="btn btn-success fw-bold py-2" :disabled="!!motivoCobro || guardando"
                            @click="cobrar">
                            <span v-if="guardando" class="spinner-border spinner-border-sm me-2"></span>{{ textoBotonCobrar }}
                        </button>
                        <div class="text-center small" :class="motivoCobro ? 'text-warning' : 'text-muted'">
                            {{ motivoCobro || 'Ctrl + Enter' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <AvisoVenta ref="aviso" />

        <!-- MODALES clientes y productos -->
        <Teleport to="body">
            <b-modal v-model="showQuickClientModal" title="Registrar Cliente Rápido" hide-footer centered size="lg"
                @hidden="showQuickClientModal = false">
                <ClientFormQuick :initial-data="quickClientData" @saved="onClientCreated"
                    @cancel="showQuickClientModal = false" />
            </b-modal>
        </Teleport>

        <Teleport to="body">
            <b-modal v-model="showQuickProductModal" title="Registrar Producto Rápido" hide-footer centered size="lg"
                @hidden="showQuickProductModal = false">
                <ProductFormQuick :initial-data="quickProductData" @saved="onProductCreated"
                    @cancel="showQuickProductModal = false" />
            </b-modal>
        </Teleport>

    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { AxiosResponse } from 'axios';
import httpClient from '@/helpers/http-client';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import type { SaleDetail, SaleConfig, SaleResponse } from '@/types/sales';
import type { PaymentMethod, PaymentMethods } from '@/types/cash';
import type { ClientAdvance } from '@/types/advances';
import type { Client } from '@/types/clients';
import type { Product } from '@/types/products';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import ProductFormQuick from '@/components/Sales/ProductFormQuick.vue';
import BuscadorProductosVenta from '@/components/Sales/BuscadorProductosVenta.vue';
import TablaLineasVenta, { type LineaVenta } from '@/components/Sales/TablaLineasVenta.vue';
import PanelClienteComprobante from '@/components/Sales/PanelClienteComprobante.vue';
import DatosVentaPanel from '@/components/Sales/DatosVentaPanel.vue';
import TotalesVenta from '@/components/Sales/TotalesVenta.vue';
import CobroContado from '@/components/Sales/CobroContado.vue';
import CobroCredito from '@/components/Sales/CobroCredito.vue';
import AdelantosVenta from '@/components/Sales/AdelantosVenta.vue';
import AvisoVenta from '@/components/Sales/AvisoVenta.vue';
import { imprimirDirecto, verPdfComprobante, type FormatoImpresion } from '@/composables/usePrintComprobante';
import { getPrecioBaseSinIgv } from '@/composables/useProductPricing';
import { cantidadEnVenta, controlaStock, indiceLineaIntacta, motivoBloqueoCobro, productoSinStock, precioCatalogo, redondear2 } from '@/helpers/ventaRetail';
import { formatFecha, partesFechaHoraPeru } from '@/helpers/fecha';
import type { CashStatusResponse } from '@/types/cash-session';
import { useAuthStore } from '@/stores/auth';
import { resolverTipAfeIgv as resolverTipAfeIgvPuro } from '@/utils/resolverTipAfeIgv';
import type { Branch } from '@/types/cash-session';
import type { TipoComprobante } from '@/types/series-comprobante';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const authStore = useAuthStore();
const route = useRoute();
const router = useRouter();

// ── Prellenado desde una Cotización Comercial (?from_quote=<id>) ────
// Módulo "Cotizaciones Comerciales": el botón "Convertir en venta" del
// detalle de la cotización navega acá con este query param. Nunca se crea
// el Sale desde el backend de cotizaciones — toda la lógica fiscal/stock
// sigue viviendo solo acá, la cotización solo aporta datos base.
const commercial_quote_id_origen = ref<string | null>(null);

// Mapa tipo_comprobante_codigo → permiso Spatie que habilita emitirlo desde
// este formulario — mismo mapa que SaleController::PERMISOS_EMISION en el
// backend (fuente de verdad real: el backend rechaza con 422 aunque este
// filtro del frontend se saltee de alguna forma). '07'/'08' (NC/ND) no
// tienen entrada acá a propósito — se emiten desde el flujo de Notas, no
// desde este formulario.
const PERMISOS_EMISION: Record<string, string> = {
    '01': 'emitir_factura',
    '03': 'emitir_boleta',
    'NV': 'emitir_nota_venta',
};

// ── Datos maestros ─────────────────────────────────────────────────
const clients = ref<Client[]>([]);
const products = ref<Product[]>([]);
const client_selected = ref<Client | undefined>(undefined);
const client_address_id = ref<number | null>(null); // T-107: null = dirección fiscal

// ── Estado de la pantalla (T-106) ─────────────────────────────────
const datosAbiertos = ref<boolean>(false);          // panel «Datos de la venta»
const lineaResaltada = ref<number | null>(null);    // última línea agregada
const buscador = ref<InstanceType<typeof BuscadorProductosVenta> | null>(null);
const aviso = ref<InstanceType<typeof AvisoVenta> | null>(null);
const guardando = ref<boolean>(false);
let siguienteUid = 1;
// Caja: null = no se pudo saber (p. ej. usuario sin permiso de caja) → no se bloquea.
const cajaAbierta = ref<boolean | null>(null);
const cajaDesde = ref<string>('');

// ── Modales rápidos ───────────────────────────────────────────────
const showQuickClientModal = ref<boolean>(false);
const quickClientData = ref<any>(null);
const showQuickProductModal = ref<boolean>(false);
const quickProductData = ref<any>(null);

// ── Configuración de la transacción ──────────────────────────────
const is_exportacion = ref<number>(0);
const n_transaction = ref<string>('');
const today = ref<string>('');
// Módulo de series de comprobantes — reemplaza el 'serie' de texto libre de
// antes. La serie real (F001/B001/NV001/...) la resuelve el backend según
// sucursal+tipo+moneda (SerieComprobanteService::resolverSerie()) — este
// formulario solo elige el TIPO, nunca la serie directamente.
const tipo_comprobante_codigo = ref<string>('03'); // default boleta — cambia según cliente
const tiposComprobante = ref<TipoComprobante[]>([]);
const branches = ref<Branch[]>([]);
const branch_id_seleccionado = ref<number | undefined>(undefined);

const puedeCambiarSucursal = computed(() => authStore.isPermitedRoute('can_switch_branch'));
// T-109: sin este permiso el precio de cada línea es el del sistema (el backend lo valida igual).
const puedeCambiarPrecio = computed(() => authStore.isPermitedRoute('cambiar_precio_venta'));
// Último T.C. SUNAT (venta) para avisar de la boleta en USD > S/ 700 sin DNI; null si no hay.
const tipoCambioVenta = ref<number | null>(null);

// Solo lo que el catálogo marca disponible para crear series
// (activo_greenter=true O 'NV', ya filtrado por el backend en
// ?disponibles_para_serie=1) Y que el usuario autenticado puede emitir
// (permiso emitir_*) — un cajero con solo emitir_nota_venta ni siquiera ve
// factura/boleta como opción.
const tiposComprobanteVisibles = computed(() =>
    tiposComprobante.value.filter((t) => {
        const permiso = PERMISOS_EMISION[t.codigo];
        return permiso ? authStore.isPermitedRoute(permiso) : false;
    })
);

const tipoEsFiscal = computed(() => {
    const tipo = tiposComprobante.value.find((t) => t.codigo === tipo_comprobante_codigo.value);
    return tipo ? tipo.es_documento_sunat : true; // default true: no ocultar de más antes de cargar el catálogo
});

// Preview en vivo de la serie que va a resolver el backend — el <select>
// viejo mostraba "F001 — Factura" directamente; el selector de tipo de
// documento nuevo no dice nada por sí solo sobre QUÉ serie/correlativo se
// va a usar. Se recalcula cada vez que cambia tipo/moneda/sucursal.
const serieResuelta = ref<{ serie: string; siguiente_correlativo: number } | null>(null);
const errorSeriePreview = ref<string>('');
let previewSerieTimeout: any = null;

const previsualizarSerie = async () => {
    if (!tipo_comprobante_codigo.value) {
        serieResuelta.value = null;
        return;
    }

    try {
        const params = new URLSearchParams();
        params.set('tipo_comprobante_codigo', tipo_comprobante_codigo.value);
        params.set('currency', currency.value);
        if (puedeCambiarSucursal.value && branch_id_seleccionado.value) {
            params.set('branch_id', String(branch_id_seleccionado.value));
        }

        const res = await httpClient.get<{ serie: string; siguiente_correlativo: number }>(
            `sales/serie-preview?${params.toString()}`
        );
        serieResuelta.value = res.data;
        errorSeriePreview.value = '';
    } catch (error: any) {
        serieResuelta.value = null;
        errorSeriePreview.value = error.response?.data?.message ?? 'No se pudo resolver la serie para este tipo de documento.';
    }
};

const type_operation = ref<string>('10');
const destino = ref<string>('amazonia'); // default Amazonía — empresa en San Martín

// ── Condiciones especiales de venta ─────────────────────────────
// '0'=ninguna, 'anticipo', '1'=retención, '2'=detracción, '3'=percepción
const condicion_especial = ref<string>('0');

// ── Adelantos disponibles del cliente seleccionado ───────────────
type AdvanceCheckoutItem = ClientAdvance & { seleccionado: boolean; monto_aplicado: number };
const availableAdvances = ref<AdvanceCheckoutItem[]>([]);
const loadingAdvances = ref<boolean>(false);

// Detracción — selección del código del Anexo SUNAT
const codigo_detraccion_sel = ref<string>('');
const porcentaje_detraccion_sel = ref<number>(0);
const monto_detraccion = ref<number>(0);

// Retención — tasa desde tax_params
const porcentaje_retencion_sel = ref<number>(0.03);
const monto_retencion = ref<number>(0);
const retencion_igv = ref<number>(0);

// Percepción — tasa desde tax_params
const porcentaje_percepcion_sel = ref<number>(0.02);
const monto_percepcion = ref<number>(0);

// ── Parámetros tributarios desde BD (no hardcode) ─────────────────
// Se cargan en config() desde sales/config → tax_configs
const parametros_tributarios = ref<Record<string, any[]>>({});
const codigos_detraccion = ref<any[]>([]);

// Helpers para leer las tasas desde la BD — con fallback seguro
//const getIgvGeneralTasa = (): number =>
//  Number(parametros_tributarios.value['IGV']?.find((t: any) => !t.ubigeos_aplicables)?.tasa_porcentaje ?? 18);

const getIgvGeneralTasa = (): number => {
    const igv_default = parametros_tributarios.value['IGV']
        ?.find((t: any) => t.es_default);
    return Number(igv_default?.tasa_porcentaje ?? 18);
};
const getIgvAmazoniaTasa = (): number =>
    Number(parametros_tributarios.value['IGV']?.find((t: any) => t.ubigeos_aplicables)?.tasa_porcentaje ?? 0);

const getIvapTasa = (): number =>
    Number(parametros_tributarios.value['IVAP']?.[0]?.tasa_porcentaje ?? 4);

const getIcbperMonto = (): number =>
    Number(parametros_tributarios.value['ICBPER']?.[0]?.tasa_porcentaje ?? 0.50);

const getRetencionTasa = (): number =>
    Number(parametros_tributarios.value['RETENCION']?.[0]?.tasa_porcentaje ?? 3) / 100;

const getPercepcionTasa = (): number =>
    Number(parametros_tributarios.value['PERCEPCION']?.[0]?.tasa_porcentaje ?? 2) / 100;

// ── Moneda ──────────────────────────────────────────────────────
const currency_iso = ref<string>('PEN'); // 'PEN' o 'USD' para el backend
const currency_symbol = ref<string>('S/.'); // S/. o $ para mostrar



// ── Detalle y pagos ───────────────────────────────────────────────
const sale_details = ref<LineaVenta[]>([]);
const currency = ref<string>('PEN');   // código ISO — Greenter requiere 'PEN' o 'USD'
const discount_total = ref<number>(0);
const discount_global = ref<number>(0);
const description = ref<string>('');

// Pagos — los escriben CobroContado.vue / CobroCredito.vue con el formato de siempre
// (method_payment, amount, date_payment, nro_operacion).
const type_payment = ref<number>(1);
const sale_payments = ref<any[]>([]);

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3): picker dinámico en vez del
// <option> hardcodeado. method_payment sigue enviándose igual que antes
// (el 'code' tal cual, ej. "YAPE") — cero cambios en el payload de la venta.
const paymentMethods = ref<PaymentMethod[]>([]);

// ── Configuración de Crédito (Módulo Amortizaciones — Fase 8) ─────
// Solo credit_type='cuotas_fijas' — 'libre' queda fuera de alcance de
// esta fase (decisión explícita, plan-modulo-amortizaciones.md §6 pto 8).
const credit_type = ref<string>('cuotas_fijas');
const num_cuotas = ref<number>(3);
const periodicidad = ref<string>('mensual');
const cronograma = ref<{ numero_cuota: number; monto_programado: number; fecha_vencimiento: string }[]>([]);
const aplica_mora = ref<boolean>(false);
const tasa_mora = ref<number>(0);
const tipo_mora = ref<string>('porcentaje_diario');
const company_mora_defaults = ref<{ mora_habilitada_default: boolean; tasa_mora_default: number | null; tipo_mora_default: string | null }>({
    mora_habilitada_default: false,
    tasa_mora_default: null,
    tipo_mora_default: null,
});

// Totales
const igv_total = ref<number>(0);
const sale_total = ref<number>(0);
const sale_subtotal = ref<number>(0);
const icbper_total = ref<number>(0);
const isc_total = ref<number>(0);
const total_payments = ref<number>(0);
// Subtotal bruto de las líneas cobrables (excluye gratuitas) — lo calcula
// sumDetails() y lo reutiliza store() para netear mto_oper_* del descuento
// global con la misma proporción que ya usa el IGV.
const subtotalBrutoCobro = ref<number>(0);

// ── Aviso flotante (sin ventana que cerrar) ───────────────────────
const avisar = (texto: string, acciones: { texto: string; fn: () => void }[] = [], ms = 6000) =>
    aviso.value?.mostrar(texto, acciones, ms);

// ── Precio según el cliente ───────────────────────────────────────
// Precio base (sin IGV) de un producto para el cliente y la cantidad total del producto en la venta (T-115):
// el precio crudo del catálogo puede venir CON IGV incluido (product.include_igv), así que se revierte para
// obtener el P. BASE real, igual que el registro de producto.
const precioBaseDe = (product: Product, cliente: Client | undefined, cantidadTotal: number): number =>
    Number(getPrecioBaseSinIgv(precioCatalogo(product, cliente, cantidadTotal).valor, product, {
        igvGeneral: getIgvGeneralTasa(),
        ivap: getIvapTasa(),
    }));

// Recalcula las líneas SIN precio puesto a mano (T-115): el precio depende del cliente y de la suma de
// las cantidades del producto en toda la venta. Cada línea que cambia recalcula sus importes (updateItem
// → sumDetails), que T-114 valida contra la cabecera. Devuelve cuántas cambiaron.
const recalcularPrecios = (): number => {
    let cambiadas = 0;
    sale_details.value.forEach((d, i) => {
        if (d.precio_manual) return;
        const nuevo = precioBaseDe(d.product, client_selected.value, cantidadEnVenta(sale_details.value, d.product.id));
        if (Math.abs(nuevo - Number(d.price_base)) > 0.0001) {
            d.price_base = nuevo;
            updateItem(i);
            cambiadas++;
        }
    });
    return cambiadas;
};

// Al cambiar de cliente se avisa cuántas líneas cambiaron de precio; al cambiar la cantidad no hay aviso
// (basta la etiqueta de la línea: «Mayorista (desde 12 u.)»).
const recalcularPreciosPorCliente = () => {
    const cambiadas = recalcularPrecios();
    if (cambiadas) avisar(`Se actualizó el precio de ${cambiadas} producto${cambiadas > 1 ? 's' : ''} según el cliente.`);
};

// La tabla avisa que una línea cambió (cantidad, precio, descuento o afectación).
const alCambiarLinea = (i: number) => {
    updateItem(i);
    recalcularPrecios();
};

// Precio elegido con el ▾ de la línea (General / Mayorista): queda como precio puesto a mano.
const elegirPrecioLinea = (i: number, nombre: 'General' | 'Mayorista') => {
    const d = sale_details.value[i];
    if (!d) return;
    const bruto = nombre === 'Mayorista' ? Number(d.product.price_company) : Number(d.product.price_general);
    d.price_base = Number(getPrecioBaseSinIgv(bruto || 0, d.product, { igvGeneral: getIgvGeneralTasa(), ivap: getIvapTasa() }));
    d.precio_manual = true;
    updateItem(i);
};

// ── Cliente ───────────────────────────────────────────────────────
// La búsqueda vive en PanelClienteComprobante.vue; acá solo lo que pasa al elegir o quitar un cliente.
const selectClient = (client: Client) => {
    client_selected.value = client;

    // ── Autocompletar tipo de documento según tipo de documento del cliente ──
    // RUC → Factura ('01'), DNI y otros → Boleta ('03') — solo si el usuario
    // puede emitir ese tipo; si no, se deja el que ya estaba seleccionado.
    const tipoSugerido = client.type_document === 'RUC' ? '01' : '03';
    if (tiposComprobanteVisibles.value.some((t) => t.codigo === tipoSugerido)) {
        tipo_comprobante_codigo.value = tipoSugerido;
    }

    // ── Sugerir destino según zona del cliente ─────────────────────
    // Si el cliente es de zona Amazonía, pre-seleccionar 'amazonia'. El vendedor puede
    // cambiarlo si el pedido se envía fuera; si no es Amazonía no se cambia nada.
    if (client.es_amazonia) {
        destino.value = 'amazonia';
    }

    cargarAdelantosDisponibles(client.id);
    recalcularPreciosPorCliente();
};

const clearClientSelection = () => {
    client_selected.value = undefined;
    client_address_id.value = null;
    tipo_comprobante_codigo.value = '03'; // resetear a boleta por defecto
    availableAdvances.value = [];
    recalcularPreciosPorCliente();
};

// ── Resolver tip_afe_igv según las reglas de negocio ─────────────
// Lógica pura extraída a src/utils/resolverTipAfeIgv.ts (testeada ahí
// con Vitest) — acá solo se cierra sobre los refs de esta vista.
const resolverTipAfeIgv = (product: Product): string =>
    resolverTipAfeIgvPuro(product, destino.value, is_exportacion.value);

// Agrega un producto a la venta (o suma a la línea que ya existe) y lleva la lista hasta esa línea.
// `opciones.precio` (>0) es un precio puesto a mano (p. ej. el de una cotización): no lo pisa el cambio de cliente.
const agregarProducto = (product: Product, opciones: { cantidad?: number; precio?: number } = {}) => {
    const cantidad = opciones.cantidad || 1;
    if (controlaStock(product) && Number(product.stock) <= 0) {
        avisar(`No hay stock disponible de «${product.title}».`);
        return;
    }

    // Aviso de stock: la suma de TODAS las líneas del producto contra su stock (T-112).
    if (controlaStock(product) && cantidadEnVenta(sale_details.value, product.id) + cantidad > Number(product.stock)) {
        avisar(`Solo hay ${product.stock} unidades de «${product.title}».`);
        return;
    }

    // Si hay una línea intacta del producto (sin descripción editada y con el precio del sistema) se le suma;
    // si no, va una fila nueva (T-112). Un precio puesto a mano (cotización) siempre abre fila propia.
    // T-115: el precio del sistema es el de la cantidad que el producto lleva AHORA; tras sumar se recalcula todo.
    const cantidadActual = cantidadEnVenta(sale_details.value, product.id);
    const precioSistema = precioBaseDe(product, client_selected.value, cantidadActual);
    let indice = Number(opciones.precio) > 0 ? -1 : indiceLineaIntacta(sale_details.value, product.id, precioSistema);
    if (indice >= 0) {
        sale_details.value[indice].quantity = Number(sale_details.value[indice].quantity) + cantidad;
        updateItem(indice);
    } else {
        const manual = Number(opciones.precio) > 0;
        const price = manual ? Number(opciones.precio) : precioBaseDe(product, client_selected.value, cantidadActual + cantidad);

        // Resolver el tipo de afectación IGV usando las reglas de negocio
        const tip_afe = resolverTipAfeIgv(product);

        sale_details.value.push({
            uid: siguienteUid++,
            precio_manual: manual,
            product: product,
            unidad_medida: product.unidad_medida || 'NIU',
            price_base: Number(price),
            quantity: cantidad,
            discount_percent: 0,
            tip_afe_igv: tip_afe,
            subtotal: 0,
            igv: 0,
            isc: 0,
            icbper: 0,
            discount: 0,
            price_final: 0,
            mto_valor_venta: 0,
            mto_base_igv: 0,
            porcentaje_igv: 0,
            total_impuestos: 0,
            tipo_isc: product.tipo_isc ?? '01',
            monto_isc_fijo: product.monto_isc_fijo ?? 0,
            contenido_neto_litros: product.contenido_neto_litros ?? undefined,
            per_icbper: 0,
        });
        indice = sale_details.value.length - 1;
        updateItem(indice);
    }

    recalcularPrecios();
    sumDetails();
    lineaResaltada.value = null;
    nextTick(() => {
        lineaResaltada.value = indice;
        setTimeout(() => { if (lineaResaltada.value === indice) lineaResaltada.value = null; }, 900);
    });
};

// ── Cálculos de cada ítem ─────────────────────────────────────────
const updateItem = (index: number) => {
    const item = sale_details.value[index];
    if (!item) return;

    const qty = Number(item.quantity) || 0;
    const price_base = Number(item.price_base) || 0;
    const disc_pct = Number(item.discount_percent) || 0;

    // mto_valor_venta = precio_base × cantidad (ANTES del descuento)
    // Greenter: SaleDetail→setMtoValorVenta()
    const mto_valor_venta = qty * price_base;

    // Descuento de la línea en monto
    const descuento_linea = mto_valor_venta * (disc_pct / 100);

    // mto_base_igv = base neta sobre la que se calcula el IGV
    // = mto_valor_venta - descuento_linea
    // Greenter: SaleDetail→setMtoBaseIgv()
    const mto_base_igv = mto_valor_venta - descuento_linea;

    // ISC según régimen — solo si el producto tiene is_isc=true.
    // No basta con mirar percentage_isc/monto_isc_fijo > 0: un producto
    // puede tener datos residuales (bug conocido) sin estar afecto a ISC.
    let isc = 0;
    if (item.product.is_isc && (item.product.percentage_isc > 0 || item.monto_isc_fijo > 0)) {
        const tipo_isc = item.tipo_isc ?? '01';
        switch (tipo_isc) {
            case '01': // Al valor (% sobre base)
                isc = mto_base_igv * (item.product.percentage_isc / 100);
                break;
            case '02': // Específico (monto fijo — por litro si el producto
                // tiene contenido_neto_litros, si no, directo por unidad)
                // PENDIENTE: contenido_neto_litros solo cubre productos líquidos.
                // Cigarrillos/combustibles (también Específico) necesitan un
                // factor de contenido no-líquido — ver nota en product/register.vue.
                isc = qty * (item.contenido_neto_litros || 1) * (item.monto_isc_fijo || 0);
                break;
            case '03': // Al valor según PVP
                isc = (price_base * qty) * (item.product.percentage_isc / 100);
                break;
        }
    }

    // Tasa de IGV según tip_afe_igv — lee de tax_params, no hardcode
    let tasa_igv = 0;
    let porcentaje_igv = 0;
    if (['10', '11'].includes(item.tip_afe_igv)) {
        tasa_igv = getIgvGeneralTasa();
        porcentaje_igv = tasa_igv;
    } else if (item.tip_afe_igv === '17') {
        tasa_igv = getIvapTasa();
        porcentaje_igv = tasa_igv;
    }
    // tip_afe_igv '20', '30', '40' → tasa 0

    // IGV se aplica sobre (mto_base_igv + ISC)
    const igv = (mto_base_igv + isc) * (tasa_igv / 100);

    // ICBPER — monto por unidad desde tax_params
    let icbper = 0;
    let per_icbper = 0;
    if (item.product.is_icbper === 2) {
        per_icbper = getIcbperMonto();   // S/ 0.50 por bolsa (desde BD)
        icbper = qty * per_icbper;
    }

    // Precio final unitario con IGV incluido
    const price_final = qty > 0
        ? (mto_base_igv + igv + isc + icbper) / qty
        : 0;

    // Total de impuestos de la línea = IGV + ISC + ICBPER
    const total_impuestos = igv + isc + icbper;

    // Guardar en el ítem
    item.mto_valor_venta = Number(mto_valor_venta.toFixed(6));
    item.mto_base_igv = Number(mto_base_igv.toFixed(6));
    item.porcentaje_igv = porcentaje_igv;
    item.subtotal = Number(mto_base_igv.toFixed(4));   // base neta = subtotal
    item.discount = Number(descuento_linea.toFixed(4));
    item.isc = Number(isc.toFixed(4));
    item.igv = Number(igv.toFixed(4));
    item.icbper = Number(icbper.toFixed(4));
    item.per_icbper = per_icbper;
    item.price_final = Number(price_final.toFixed(4));
    item.total_impuestos = Number(total_impuestos.toFixed(4));

    sumDetails();
};

// Quitar una línea no pide confirmación: avisa y deja «Deshacer».
const removeItem = (index: number) => {
    const [quitada] = sale_details.value.splice(index, 1);
    recalcularPrecios(); // al bajar la suma del producto puede dejar de ser mayorista (T-115)
    sumDetails();
    if (!quitada) return;
    avisar(`Quitaste «${quitada.product.title}»`, [{
        texto: 'Deshacer',
        fn: () => {
            sale_details.value.splice(Math.min(index, sale_details.value.length), 0, quitada);
            recalcularPrecios();
            sumDetails();
        },
    }]);
};

// ── Tasas de IGV para mostrar en la tabla de totales ─────────────
const getTasaIgv = (item?: SaleDetail): number => {
    if (!item) return getIgvGeneralTasa();
    if (item.tip_afe_igv === '17') return getIvapTasa();
    if (['20', '30', '40'].includes(item.tip_afe_igv)) return 0;
    return getIgvGeneralTasa();
};

const getTasaIgvGlobal = (): number => {
    if (sale_details.value.length === 0) return getIgvGeneralTasa();
    return getTasaIgv(sale_details.value[0]);
};

// ── Cálculo de totales ────────────────────────────────────────────
const getSubTotalSale = (): number => {
    const descuento = Number(discount_global.value) || 0;
    return Number((sale_subtotal.value - descuento).toFixed(4));
};

const getIgvTotal = (): number => {
    // igv_total ya viene recalculado en sumDetails() sobre la base
    // neta del descuento global (prorrateado por línea)
    return Number(igv_total.value.toFixed(4));
};

const getTotalFactura = (): number => {
    return getSubTotalSale() + getIgvTotal() + icbper_total.value + isc_total.value;
};

const getTotalSales = (): number => sale_total.value;

const getBaseForRegimen = (): number => getTotalFactura();

// ── Subtotal de línea neto del descuento global (prorrateado) ────────
// Único punto de cálculo para "subtotal de línea ya con el descuento
// global aplicado" — lo usan tanto el IGV (sumDetails()) como los
// mto_oper_* que se mandan a SUNAT (store()). Antes cada uno lo
// calculaba por separado y solo el IGV restaba el descuento; ese
// desfase entre el total (neto) y mto_oper_exoneradas/gravadas/etc.
// (brutos) causaba el error SUNAT 3275 en ventas con descuento global.
const subtotalNetoDescuentoGlobal = (d: SaleDetail, subtotalBruto: number): number => {
    const descuento = Number(discount_global.value) || 0;
    const proporcion = subtotalBruto > 0 ? d.subtotal / subtotalBruto : 0;
    return d.subtotal - (descuento * proporcion);
};

const sumDetails = () => {
    if (isNaN(discount_global.value) || discount_global.value === null) {
        discount_global.value = 0;
    }

    // Excluir operaciones gratuitas (tip_afe_igv = 11) de los totales
    const lineas_cobro = sale_details.value.filter(d => d.tip_afe_igv !== '11');

    // ── IGV recalculado sobre la base neta del descuento global ──────
    // El descuento global se prorratea por línea según su peso en el
    // subtotal bruto, y el IGV se recalcula sobre esa base ya descontada
    // respetando la tasa propia de cada línea — necesario porque una
    // misma venta puede mezclar líneas gravadas, exoneradas e IVAP.
    const subtotal_bruto = lineas_cobro.reduce((s, d) => s + d.subtotal, 0);
    subtotalBrutoCobro.value = subtotal_bruto;
    const descuento_global_actual = Number(discount_global.value) || 0;
    igv_total.value = Number(lineas_cobro.reduce((s, d) => {
        const base_neta = subtotalNetoDescuentoGlobal(d, subtotal_bruto);
        return s + (base_neta * (d.porcentaje_igv / 100));
    }, 0).toFixed(4));
    // ICBPER: monto fijo por unidad (bolsa), no depende del valor de venta
    // → nunca se prorratea con el descuento global. Confirmado con el usuario.
    icbper_total.value = Number(lineas_cobro.reduce((s, d) => s + d.icbper, 0).toFixed(4));

    // ISC: solo se prorratea con el descuento global si el régimen del
    // producto es porcentual sobre el valor de venta ('01' Al valor, '03'
    // Al valor según PVP) — mismo criterio que el IGV. El régimen '02'
    // (Específico, monto fijo x unidad) se comporta como ICBPER: no se
    // prorratea. PENDIENTE DE VALIDAR CON DATOS REALES: no hay productos
    // con ISC activo en pruebas todavía (ver conversación 2026-07-09).
    isc_total.value = Number(lineas_cobro.reduce((s, d) => {
        if (!d.product?.is_isc || d.tipo_isc === '02') {
            return s + d.isc;
        }
        const proporcion = subtotal_bruto > 0 ? d.subtotal / subtotal_bruto : 0;
        const descuento_linea = descuento_global_actual * proporcion;
        const percentage = d.product?.percentage_isc ?? 0;
        if (d.tipo_isc === '03') {
            return s + ((d.mto_valor_venta - descuento_linea) * (percentage / 100));
        }
        return s + ((d.subtotal - descuento_linea) * (percentage / 100)); // '01'
    }, 0).toFixed(4));
    sale_subtotal.value = Number(lineas_cobro.reduce((s, d) => s + d.subtotal, 0).toFixed(4));
    discount_total.value = Number(lineas_cobro.reduce((s, d) => s + d.discount, 0).toFixed(4));
    total_payments.value = Number(sale_payments.value.reduce((s, p) => s + p.amount, 0).toFixed(2));

    // ── Calcular montos de regímenes especiales ───────────────────
    // Las tasas vienen de tax_params (BD), no hardcodeadas
    const base_regimen = getBaseForRegimen();

    monto_retencion.value = 0;
    monto_detraccion.value = 0;
    monto_percepcion.value = 0;

    // Actualizar tasas desde BD cada vez que se recalcula
    porcentaje_retencion_sel.value = getRetencionTasa();
    porcentaje_percepcion_sel.value = getPercepcionTasa();

    const cond = condicion_especial.value;
    if (cond === '1') {
        // Retención: solo aplica si supera el mínimo (S/ 700 por defecto)
        const minimo_retencion = Number(
            parametros_tributarios.value['RETENCION']?.[0]?.monto_minimo ?? 700
        );
        if (base_regimen > minimo_retencion) {
            monto_retencion.value = Number(
                (base_regimen * porcentaje_retencion_sel.value).toFixed(2)
            );
        }
    } else if (cond === '2' && porcentaje_detraccion_sel.value > 0) {
        // Detracción: solo aplica si tiene código seleccionado y supera el mínimo
        const codigo_sel = codigos_detraccion.value.find(d => d.codigo === codigo_detraccion_sel.value);
        const minimo_detraccion = Number(codigo_sel?.monto_minimo ?? 700);



        if (base_regimen > minimo_detraccion) {
            monto_detraccion.value = Number(
                (base_regimen * porcentaje_detraccion_sel.value).toFixed(2)
            );
        }
    } else if (cond === '3') {
        // Percepción
        const minimo_percepcion = Number(
            parametros_tributarios.value['PERCEPCION']?.[0]?.monto_minimo ?? 700
        );
        if (base_regimen > minimo_percepcion) {
            monto_percepcion.value = Number(
                (base_regimen * porcentaje_percepcion_sel.value).toFixed(2)
            );
        }
    }

    // Total final = base + percepción - retención - detracción
    sale_total.value = Number((
        base_regimen
        + monto_percepcion.value
        - monto_retencion.value
        - monto_detraccion.value
    ).toFixed(4));
};

// ── Cuando cambia el código de detracción ─────────────────────────
const onDetractionCodeChange = () => {
    const codigo_sel = codigos_detraccion.value
        .find(d => d.codigo === codigo_detraccion_sel.value);
    // hay que dividir entre 100 para obtener 0.12
    porcentaje_detraccion_sel.value = codigo_sel
        ? Number(codigo_sel.tasa_porcentaje) / 100
        : 0;

    sumDetails();
};

// ── Adelantos disponibles del cliente ────────────────────────────
const cargarAdelantosDisponibles = async (clientId: number | string) => {
    loadingAdvances.value = true;
    availableAdvances.value = [];
    try {
        const { data } = await httpClient.get(`clients/${clientId}/advances`);
        availableAdvances.value = (data.advances ?? []).map((a: ClientAdvance) => ({
            ...a,
            seleccionado: false,
            monto_aplicado: 0,
        }));
    } catch {
        // Silencioso — un fallo acá no debe bloquear el registro de la venta.
        availableAdvances.value = [];
    } finally {
        loadingAdvances.value = false;
    }
};

const onAdvanceToggle = (a: AdvanceCheckoutItem) => {
    a.monto_aplicado = a.seleccionado ? a.available_balance : 0;
};

// ── Filtro por moneda: un adelanto solo se puede aplicar a una venta de
// su misma moneda (mismo guard que SaleController::store(), backend) —
// acá se filtran del picker directamente, en vez de solo deshabilitarlos,
// porque antes se podía tildar un adelanto USD sobre una venta PEN y
// totalAdelantosAplicados() restaba una moneda de otra como si fueran la
// misma unidad, mostrando un total falso (hallazgo de auditoría del
// módulo, 2026-08-21).
const adelantosDisponiblesFiltrados = computed(() =>
    availableAdvances.value.filter(a => a.currency === currency.value)
);

// Si la moneda de la venta cambia después de tildar un adelanto, ese
// adelanto deja de verse en el picker (filtro de arriba) pero sin este
// watch su selección quedaba "viva" por debajo — totalAdelantosAplicados()
// y el payload final lo seguían incluyendo aunque ya no fuera visible.
watch(currency, (nuevaMoneda) => {
    availableAdvances.value.forEach(a => {
        if (a.currency !== nuevaMoneda && a.seleccionado) {
            a.seleccionado = false;
            a.monto_aplicado = 0;
        }
    });
});

const totalAdelantosAplicados = (): number => {
    return availableAdvances.value
        .filter(a => a.seleccionado)
        .reduce((s, a) => s + (Number(a.monto_aplicado) || 0), 0);
};

// ── Configuración de Crédito (Módulo Amortizaciones — Fase 8) ─────
// Monto que financia el cronograma: total menos adelantos aplicados menos
// pagos iniciales ya ingresados (ej. cuota inicial en efectivo) — mismo
// cálculo que ya usa store() para 'debt', reusado acá para el preview y
// la validación en vivo de la suma del cronograma.
const montoAFinanciar = (): number => {
    const totalAdelantos = condicion_especial.value === 'anticipo' ? totalAdelantosAplicados() : 0;
    return Math.max(0, getTotalSales() - totalAdelantos - total_payments.value);
};

const sumaCronograma = (): number =>
    cronograma.value.reduce((s, c) => s + (Number(c.monto_programado) || 0), 0);

const aplicarDefaultsMora = () => {
    aplica_mora.value = company_mora_defaults.value.mora_habilitada_default;
    tasa_mora.value = company_mora_defaults.value.tasa_mora_default ?? 0;
    tipo_mora.value = company_mora_defaults.value.tipo_mora_default ?? 'porcentaje_diario';
};

// ── Guardar venta ─────────────────────────────────────────────────
const store = async () => {
    if (!client_selected.value) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Selecciona un cliente.' }); return;
    }
    if (sale_details.value.length === 0) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Agrega al menos un producto.' }); return;
    }
    const totalAdelantos = condicion_especial.value === 'anticipo' ? totalAdelantosAplicados() : 0;
    const totalConAdelanto = getTotalSales() - totalAdelantos;

    // Re-validar acá (no solo en addPayment()): el total pudo bajar después
    // de agregar un pago (se quitó una línea, cambió un descuento/régimen),
    // dejando un pago ya agregado por encima del total sin que nada lo avise.
    if (total_payments.value > totalConAdelanto + 0.009) {
        (Swal as TVueSwalInstance).fire({
            icon: 'error', title: 'Error',
            text: `Los pagos (${currency.value} ${total_payments.value.toFixed(2)}) superan el total a pagar ` +
                `(${currency.value} ${totalConAdelanto.toFixed(2)}). Ajusta los pagos antes de guardar.`,
        }); return;
    }

    // Requiere al menos un pago solo si NO es crédito — una venta a
    // crédito puede financiarse 100% vía cronograma sin pago inicial.
    if (sale_payments.value.length === 0 && totalConAdelanto > 0 && type_payment.value != 2) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Agrega al menos un pago para la venta.' }); return;
    }
    if (condicion_especial.value === '2' && !codigo_detraccion_sel.value) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Selecciona el código de detracción.' }); return;
    }
    if (condicion_especial.value === 'anticipo') {
        if (totalAdelantos <= 0) {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Selecciona al menos un adelanto y un monto a aplicar.' }); return;
        }
        if (totalAdelantos > getTotalSales()) {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'La suma de adelantos aplicados no puede superar el total de la venta.' }); return;
        }
    }

    // ── Validación de Configuración de Crédito (Módulo Amortizaciones — Fase 8) ──
    // Espeja la validación de validarConfiguracionCredito() en el backend
    // (SaleController.php) para dar feedback inmediato — el backend sigue
    // siendo la fuente de verdad, esto solo evita un viaje HTTP inútil.
    if (type_payment.value == 2) {
        if (credit_type.value !== 'cuotas_fijas') {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Solo se soporta crédito con cuotas fijas por ahora.' }); return;
        }
        if (cronograma.value.length === 0) {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Genera el cronograma de cuotas antes de guardar.' }); return;
        }
        if (Math.abs(sumaCronograma() - montoAFinanciar()) > 0.01) {
            (Swal as TVueSwalInstance).fire({
                icon: 'error', title: 'Error',
                text: `La suma del cronograma (${currency.value} ${sumaCronograma().toFixed(2)}) no calza con el monto ` +
                    `a financiar (${currency.value} ${montoAFinanciar().toFixed(2)}).`,
            }); return;
        }
        if (aplica_mora.value && (!tasa_mora.value || tasa_mora.value <= 0)) {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Si aplica mora, la tasa debe ser mayor a 0.' }); return;
        }
    }

    // Estado de pago derivado automáticamente (considera lo ya cubierto por adelantos)
    let estado_pago = 1; // pendiente
    if (total_payments.value >= totalConAdelanto) estado_pago = 3;      // pagado completo
    else if (total_payments.value > 0 || totalAdelantos > 0) estado_pago = 2;      // pago parcial

    // Mapear condicion_especial → retencion_igv (campo de la BD)
    const retencion_igv_bd = condicion_especial.value === 'anticipo' ? 0
        : ['1', '2', '3'].includes(condicion_especial.value) ? Number(condicion_especial.value)
            : 0;

    const data = {
        // Identificación
        tipo_comprobante_codigo: tipo_comprobante_codigo.value,
        ...(puedeCambiarSucursal.value && branch_id_seleccionado.value
            ? { branch_id: branch_id_seleccionado.value }
            : {}),
        n_transaction: n_transaction.value,
        date: today.value,

        // Cliente
        client_id: client_selected.value.id,
        // T-109: de qué cotización sale la venta; el backend acepta sus precios sin el permiso de precio.
        commercial_quote_id: commercial_quote_id_origen.value,
        client_address_id: client_address_id.value,
        type_client: client_selected.value.type_client,
        // Tipo de documento según Catálogo 06 SUNAT — ya calculado en el cliente
        cod_tipo_doc_cliente: client_selected.value.cod_tipo_doc_sunat ?? '1',

        // Configuración de la operación
        currency: currency.value,         // 'PEN' o 'USD' (código ISO)
        is_exportacion: is_exportacion.value,
        destino: destino.value,          // 'amazonia' o 'nacional'
        type_payment: type_payment.value,

        // Regímenes especiales
        retencion_igv: retencion_igv_bd,
        codigo_detraccion: codigo_detraccion_sel.value || null,
        porcentaje_detraccion: porcentaje_detraccion_sel.value,
        monto_detraccion: monto_detraccion.value,
        porcentaje_percepcion: porcentaje_percepcion_sel.value,
        monto_percepcion: monto_percepcion.value,
        porcentaje_retencion: porcentaje_retencion_sel.value,
        monto_retencion: monto_retencion.value,

        // Adelantos aplicados — el backend valida saldo/pertenencia al
        // cliente y neta el total del comprobante (ver módulo Adelantos)
        advance_applications: availableAdvances.value
            .filter(a => a.seleccionado && a.monto_aplicado > 0)
            .map(a => ({ advance_id: a.id, amount: a.monto_aplicado })),

        // Totales calculados (el backend no recalcula — usa estos valores)
        subtotal: sale_subtotal.value,
        igv: igv_total.value,
        total: sale_total.value,
        discount: discount_total.value,
        discount_global: discount_global.value,
        igv_discount_general: 0,  // deprecated — mantener por compatibilidad

        // Totales por tipo de operación para Greenter — netos del descuento
        // global (prorrateado, ver subtotalNetoDescuentoGlobal()). SUNAT
        // rechaza (error 3275) si estos no cuadran con el total, que ya
        // sale neto del descuento (ver getSubTotalSale()).
        mto_oper_gravadas: sale_details.value.filter(d => d.tip_afe_igv === '10')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        mto_oper_exoneradas: sale_details.value.filter(d => d.tip_afe_igv === '20')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        mto_oper_inafectas: sale_details.value.filter(d => d.tip_afe_igv === '30')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        mto_oper_exportacion: sale_details.value.filter(d => d.tip_afe_igv === '40')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        // Gratuitas quedan brutas — no llevan descuento, están excluidas de
        // lineas_cobro/subtotal_bruto en sumDetails().
        mto_oper_gratuitas: sale_details.value.filter(d => d.tip_afe_igv === '11').reduce((s, d) => s + d.subtotal, 0),

        // Impuestos desagregados
        isc_total: isc_total.value,
        icbper_total: icbper_total.value,
        ivap_total: sale_details.value.filter(d => d.tip_afe_igv === '17').reduce((s, d) => s + d.igv, 0),
        total_impuestos: sale_details.value.reduce((s, d) => s + d.total_impuestos, 0),
        valor_venta: getSubTotalSale(),
        mto_imp_venta: Math.round(getTotalFactura() * 10) / 10,
        redondeo: Math.round((Math.round(getTotalFactura() * 10) / 10 - getTotalFactura()) * 100) / 100,

        // Estado y deuda — netos del adelanto aplicado (el dinero del
        // adelanto ya se recibió antes, en su propio comprobante)
        state_payment: estado_pago,
        debt: Math.max(0, totalConAdelanto - total_payments.value),
        paid_out: total_payments.value + totalAdelantos,

        // Detalles — incluyen todos los campos para Greenter
        // (sin los campos solo de pantalla — uid y precio_manual —: el JSON es el mismo de siempre)
        sale_details: sale_details.value.map(({ uid, precio_manual, ...linea }) => linea),

        // Pagos
        payments: sale_payments.value,

        description: description.value,

        // Configuración de Crédito (Módulo Amortizaciones — Fase 8) — solo
        // se manda si es venta a crédito, mismo criterio que el backend
        // usa para derivar condicion_pago de type_payment.
        ...(type_payment.value == 2 ? {
            credit_type: credit_type.value,
            cronograma: cronograma.value,
            aplica_mora: aplica_mora.value,
            tasa_mora: aplica_mora.value ? tasa_mora.value : null,
            tipo_mora: aplica_mora.value ? tipo_mora.value : null,
        } : {}),
    };

    if (guardando.value) return;
    guardando.value = true;
    // Lo que sale en el aviso: el número real recién se asigna al enviar a SUNAT, así que se muestra la serie.
    const nombreTipo = tiposComprobante.value.find(t => t.codigo === tipo_comprobante_codigo.value)?.nombre ?? 'Venta';
    const serieAviso = serieResuelta.value?.serie ?? '';
    const esCredito = type_payment.value == 2;
    const totalAviso = totalAPagar.value;
    const monedaAviso = currency.value === 'USD' ? 'US$' : 'S/';

    try {
        const resp: AxiosResponse<SaleResponse> = await httpClient.post('sales', data);

        if (resp.data.error) {
            (Swal as TVueSwalInstance).fire('Error interno', resp.data.error.message, 'error');
            return;
        }
        if (resp.data.code === 200) {
            const saleId = resp.data.sale_id;
            if (saleId) {
                // Cierra el flujo "Convertir en venta" de Cotizaciones
                // Comerciales — la venta ya se guardó bien, así que un
                // fallo acá (red, etc.) solo se loguea, nunca bloquea al
                // vendedor: decisión consciente, documentada en
                // CommercialQuoteController::marcarConvertida().
                if (commercial_quote_id_origen.value) {
                    try {
                        await httpClient.post(`commercial-quotes/${commercial_quote_id_origen.value}/mark-converted`, {
                            sale_id: saleId,
                        });
                    } catch (markError) {
                        console.error('No se pudo marcar la cotización comercial como convertida', markError);
                    }
                }
            }
            resetData();
            // Sin ventana de éxito: pantalla limpia, foco en el buscador y un aviso con Reimprimir / Ver PDF A4.
            if (saleId) {
                const formatoDefault = (useAuthStore().getUser()?.formato_impresion_default ?? 'a4') as FormatoImpresion;
                const cobro = esCredito ? 'a crédito' : `cobrado ${monedaAviso} ${totalAviso.toFixed(2)}`;
                avisar(`✓ ${nombreTipo} ${serieAviso} guardada, ${cobro}.`, [
                    { texto: 'Reimprimir', fn: () => { imprimirDirecto(saleId, formatoDefault); } },
                    { texto: 'Ver PDF A4', fn: () => { verPdfComprobante(saleId, 'a4'); } },
                ], 12000);
                imprimirDirecto(saleId, formatoDefault);
            }
            previsualizarSerie();
            cargarCaja();
            nextTick(() => buscador.value?.enfocar());
        }
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'Error inesperado', 'error');
    } finally {
        guardando.value = false;
    }
};

// ── Limpiar formulario ─────────────────────────────────────────────
const resetData = () => {
    commercial_quote_id_origen.value = null;
    client_selected.value = undefined;
    client_address_id.value = null;
    sale_details.value = [];
    sale_payments.value = [];
    discount_total.value = 0;
    sale_subtotal.value = 0;
    sale_total.value = 0;
    igv_total.value = 0;
    icbper_total.value = 0;
    isc_total.value = 0;
    total_payments.value = 0;
    description.value = '';
    condicion_especial.value = '0';
    monto_retencion.value = 0;
    monto_detraccion.value = 0;
    monto_percepcion.value = 0;
    codigo_detraccion_sel.value = '';
    porcentaje_detraccion_sel.value = 0;
    discount_global.value = 0;
    availableAdvances.value = [];
    tipo_comprobante_codigo.value = '03';
    is_exportacion.value = 0;
    currency.value = 'PEN';
    destino.value = 'amazonia';
    type_payment.value = 1;
    credit_type.value = 'cuotas_fijas';
    num_cuotas.value = 3;
    periodicidad.value = 'mensual';
    cronograma.value = [];
    aplicarDefaultsMora();
    sumDetails();
};

// ── Modales rápidos ───────────────────────────────────────────────
// `data` = lo ya consultado en RENIEC/SUNAT para prellenar el formulario (null = vacío).
const openQuickClientModalWithData = (data: any | null) => {
    quickClientData.value = data;
    showQuickClientModal.value = true;
};
const onClientCreated = (client: Client) => {
    clients.value.unshift(client);
    selectClient(client);
    showQuickClientModal.value = false;
};
// «+ Crear producto o servicio» del buscador: el formulario rápido ya tiene Bien / Servicio.
const openQuickProductModal = (nombre = '') => {
    quickProductData.value = nombre ? { title: nombre } : null;
    showQuickProductModal.value = true;
};
const onProductCreated = (product: Product) => {
    products.value.unshift(product);
    agregarProducto(product);
    showQuickProductModal.value = false;
};

// ── Configuración inicial ─────────────────────────────────────────
const config = async () => {
    try {
        const res: AxiosResponse<SaleConfig> = await httpClient.get('sales/config');
        n_transaction.value = res.data.n_transaction;
        today.value = res.data.today;
        if (products.value.length === 0) {
            clients.value = res.data.clients.data;
            products.value = res.data.products.data;
        }
        // Cargar parámetros tributarios desde BD — no hardcode
        parametros_tributarios.value = (res.data as any).parametros_tributarios ?? {};
        codigos_detraccion.value = (res.data as any).codigos_detraccion ?? [];
        const tc = Number((res.data as any).tipo_cambio_venta);
        tipoCambioVenta.value = tc > 0 ? tc : null;

        // Inicializar tasas de regímenes desde BD
        porcentaje_retencion_sel.value = getRetencionTasa();
        porcentaje_percepcion_sel.value = getPercepcionTasa();

        // Defaults de mora de la empresa (Módulo Amortizaciones — Fase 8) —
        // prellenan el checkbox aplica_mora de Configuración de Crédito,
        // editable por venta puntual.
        company_mora_defaults.value = (res.data as any).company_mora_defaults ?? company_mora_defaults.value;
        aplicarDefaultsMora();
    } catch (error) {
        console.error(error);
    }
};

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Endpoint propio (no
// sales/config) — este catálogo no es exclusivo de ventas.
const loadPaymentMethods = async () => {
    try {
        const res: AxiosResponse<PaymentMethods> = await httpClient.get('payment-methods?active=1');
        paymentMethods.value = res.data.payment_methods;
    } catch (error) {
        console.error(error);
    }
};

// ── Módulo de series de comprobantes ──────────────────────────────
const cargarTiposYSucursales = async () => {
    try {
        const resTipos = await httpClient.get<{ tipos_comprobante: TipoComprobante[] }>(
            'tipos-comprobante?disponibles_para_serie=1'
        );
        tiposComprobante.value = resTipos.data.tipos_comprobante;

        if (puedeCambiarSucursal.value) {
            const resBranches = await httpClient.get<{ branches: Branch[] }>('branches?active=1');
            branches.value = resBranches.data.branches;
            branch_id_seleccionado.value = branches.value[0]?.id;
        }

        previsualizarSerie();
    } catch (error) {
        console.error(error);
    }
};

// ── Limpieza de campos fiscales al cambiar de tipo de documento ────
// Mismo principio que "Configuración de Crédito": regenerar en vez de
// conservar estado potencialmente inconsistente. Se limpia SIEMPRE que el
// tipo cambia (no solo al entrar/salir de nota de venta) — si el usuario
// vuelve a un tipo fiscal, los campos aparecen vacíos, nunca con el valor
// que tenían antes de ocultarse.
const resetCamposFiscales = () => {
    condicion_especial.value = '0';
    destino.value = 'amazonia';
    is_exportacion.value = 0;
    codigo_detraccion_sel.value = '';
    porcentaje_detraccion_sel.value = 0;
    monto_detraccion.value = 0;
    monto_retencion.value = 0;
    monto_percepcion.value = 0;
};

watch(tipo_comprobante_codigo, () => resetCamposFiscales());

// Debounce corto: currency/branch_id_seleccionado pueden cambiar seguido
// (ej. el usuario tipeando), tipo_comprobante_codigo cambia por selección
// directa — todos disparan la misma previsualización.
watch([tipo_comprobante_codigo, currency, branch_id_seleccionado], () => {
    clearTimeout(previewSerieTimeout);
    previewSerieTimeout = setTimeout(previsualizarSerie, 200);
});

// ── Watchers ──────────────────────────────────────────────────────
watch(discount_global, () => sumDetails());
watch(retencion_igv, () => sumDetails()); // compatibilidad

watch(condicion_especial, (valor) => {
    // Activar exportación si se seleccionó esa condición
    is_exportacion.value = valor === 'exportacion' ? 1 : 0;

    // Recalcular todos los ítems porque el tip_afe_igv cambia
    sale_details.value.forEach((item, index) => {
        item.tip_afe_igv = resolverTipAfeIgv(item.product);
        updateItem(index);
    });

    // Moneda: exportación siempre en USD por defecto
    // (el vendedor puede cambiarla si negoció en soles)
    if (valor === 'exportacion') {
        currency.value = 'USD';
    } else if (is_exportacion.value === 0) {
        currency.value = 'PEN';
    }

    sumDetails();
});



// Al pasar de contado a crédito (o al revés) se arma el cobro de cero: los pagos los vuelve a escribir
// el componente de cobro que queda montado.
watch(type_payment, () => {
    sale_payments.value = [];
    sumDetails();
});

// Los pagos los escriben los componentes de cobro: se re-suman acá (total_payments, estado, deuda).
watch(sale_payments, () => sumDetails(), { deep: true });

watch(is_exportacion, (value) => {
    // Código ISO para Greenter: 'PEN' = soles, 'USD' = dólares
    currency.value = value === 1 ? 'USD' : 'PEN';
    sale_details.value = [];
    discount_global.value = 0;
    sumDetails();
});

watch(destino, () => {
    // Cuando cambia el destino, recalcular tip_afe_igv de todos los ítems
    // porque la exoneración por Ley 27037 depende del destino
    sale_details.value.forEach((item, index) => {
        item.tip_afe_igv = resolverTipAfeIgv(item.product);
        updateItem(index);
    });
    sumDetails();
});

// También vigilar cambios en moneda para actualizar el símbolo
watch(currency_iso, (nuevo) => {
    currency_symbol.value = nuevo === 'USD' ? '$' : 'S/.';
});

// Trae cliente/ítems de una Cotización Comercial (GET .../for-sale, solo
// lectura) y prellena el formulario reusando selectClient()/agregarProducto()
// selectAndAddProduct() ya existentes — así el cálculo de IGV/stock/
// afectación tributaria sigue siendo exactamente el mismo que el de
// cualquier venta normal, sin duplicar nada. Corre DESPUÉS de config()
// (necesita parametros_tributarios ya cargados para getPrecioBaseSinIgv()).
const prellenarDesdeCotizacion = async (quoteId: string) => {
    try {
        const { data } = await httpClient.get(`commercial-quotes/${quoteId}/for-sale`);

        if (data.client) {
            // Cliente completo (no el resumen parcial que trae for-sale) —
            // selectClient() necesita campos que ese resumen no incluye
            // (cod_tipo_doc_sunat, es_amazonia, etc.).
            const { data: clienteCompleto } = await httpClient.get(`clients/${data.client.id}`);
            selectClient(clienteCompleto.client);
        } else if (data.client_name_free) {
            (Swal as TVueSwalInstance).fire({
                icon: 'info',
                title: 'Cliente de la cotización',
                text: `${data.client_name_free}${data.client_phone_free ? ' — ' + data.client_phone_free : ''}. Selecciona o crea el cliente para esta venta.`,
            });
        }

        const itemsSinProducto: string[] = [];
        for (const item of data.items) {
            if (item.product_id) {
                const { data: productoCompleto } = await httpClient.get(`products/${item.product_id}`);
                agregarProducto(productoCompleto.product, { cantidad: item.quantity, precio: item.unit_price });
            } else {
                itemsSinProducto.push(item.description);
            }
        }

        if (itemsSinProducto.length > 0) {
            (Swal as TVueSwalInstance).fire({
                icon: 'warning',
                title: 'Ítems sin producto asociado',
                html: 'Estos ítems de la cotización deben agregarse manualmente:<br>'
                    + itemsSinProducto.map((d) => `• ${d}`).join('<br>'),
            });
        }

        commercial_quote_id_origen.value = quoteId;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'No se pudo cargar la cotización comercial.', 'error');
    }
};

// ── Caja (T-106) ──────────────────────────────────────────────────
// Misma sesión de caja que la pantalla de Caja (cash/status). Si el usuario no tiene acceso al módulo,
// la consulta falla y queda en «desconocida» (null): ahí no se bloquea nada, lo valida el backend.
const cargarCaja = async () => {
    try {
        const { data } = await httpClient.get<CashStatusResponse>('cash/status');
        cajaAbierta.value = !!data.has_open_session;
        cajaDesde.value = data.session?.opened_at ? partesFechaHoraPeru(data.session.opened_at).hora : '';
    } catch {
        cajaAbierta.value = null;
        cajaDesde.value = '';
    }
};

// ── Totales a mostrar y a cobrar ──────────────────────────────────
const simbolo = computed(() => (currency.value === 'USD' ? 'US$' : 'S/'));
const dinero = (n: number) => `${simbolo.value} ${n.toFixed(2)}`;

// Lo que se cobra ahora: total de la venta menos los adelantos aplicados.
const totalAPagar = computed(() => {
    const adelantos = condicion_especial.value === 'anticipo' ? totalAdelantosAplicados() : 0;
    return Math.max(0, redondear2(sale_total.value - adelantos));
});
const adelantosExceden = computed(() =>
    condicion_especial.value === 'anticipo' && totalAdelantosAplicados() > sale_total.value + 0.009);

// Filas del resumen: solo las que aplican (misma base que los mto_oper_* del payload, netas del descuento global).
const filasTotales = computed(() => {
    if (sale_details.value.length === 0) return [];
    const base = (...afectaciones: string[]) => sale_details.value
        .filter(d => afectaciones.includes(d.tip_afe_igv))
        .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0);
    const filas: { etiqueta: string; valor: string; clase?: string }[] = [];
    const desc = discount_total.value + (Number(discount_global.value) || 0);
    if (desc > 0) filas.push({ etiqueta: 'Descuentos', valor: `− ${dinero(desc)}`, clase: 'text-danger' });
    const g = base('10'), ivap = base('17'), ex = base('20'), ina = base('30'), exp = base('40'), gra = base('11');
    if (g > 0) filas.push({ etiqueta: 'Op. gravada', valor: dinero(g) });
    if (ivap > 0) filas.push({ etiqueta: 'Op. gravada IVAP', valor: dinero(ivap) });
    if (getIgvTotal() > 0) filas.push({ etiqueta: 'IGV', valor: dinero(getIgvTotal()) });
    if (ex > 0) filas.push({ etiqueta: 'Op. exonerada', valor: dinero(ex) });
    if (ina > 0) filas.push({ etiqueta: 'Op. inafecta', valor: dinero(ina) });
    if (exp > 0) filas.push({ etiqueta: 'Exportación', valor: dinero(exp) });
    if (gra > 0) filas.push({ etiqueta: 'Op. gratuita', valor: dinero(gra) });
    if (icbper_total.value > 0) filas.push({ etiqueta: 'ICBPER (bolsas)', valor: dinero(icbper_total.value) });
    if (isc_total.value > 0) filas.push({ etiqueta: 'ISC', valor: dinero(isc_total.value) });
    if (condicion_especial.value === '3') filas.push({ etiqueta: `(+) Percepción ${(porcentaje_percepcion_sel.value * 100).toFixed(1)}%`, valor: dinero(monto_percepcion.value), clase: 'text-info' });
    if (condicion_especial.value === '1') filas.push({ etiqueta: `(−) Retención ${(porcentaje_retencion_sel.value * 100).toFixed(1)}%`, valor: `− ${dinero(monto_retencion.value)}`, clase: 'text-danger' });
    if (condicion_especial.value === '2') filas.push({ etiqueta: `(−) Detracción ${(porcentaje_detraccion_sel.value * 100).toFixed(1)}% [${codigo_detraccion_sel.value}]`, valor: dinero(monto_detraccion.value), clase: 'text-warning' });
    if (condicion_especial.value === 'anticipo' && totalAdelantosAplicados() > 0) {
        filas.push({ etiqueta: 'Total de la venta', valor: dinero(sale_total.value) });
        filas.push({ etiqueta: '(−) Adelantos aplicados', valor: `− ${dinero(totalAdelantosAplicados())}`, clase: 'text-primary' });
    }
    return filas;
});
const etiquetaTotal = computed(() => (condicion_especial.value === 'anticipo' ? 'Monto a pagar' : 'Total'));

// ── Botón Cobrar: se deshabilita con el motivo debajo ──────────────
const esCredito = computed(() => type_payment.value == 2);
const motivoCobro = computed(() => {
    return motivoBloqueoCobro({
        lineas: sale_details.value.length,
        sinStock: productoSinStock(sale_details.value),
        hayCliente: !!client_selected.value,
        tipoComprobante: tipo_comprobante_codigo.value,
        clienteTipoDocumento: client_selected.value?.type_document ?? null,
        clienteNDocumento: client_selected.value?.n_document ?? null,
        total: sale_total.value,
        moneda: currency.value,
        tipoCambioVenta: tipoCambioVenta.value,
        esCredito: esCredito.value,
        cajaAbierta: cajaAbierta.value,
        cobraAhora: esCredito.value ? total_payments.value > 0 : totalAPagar.value > 0,
        faltaCobrar: esCredito.value ? 0 : Math.max(0, redondear2(totalAPagar.value - total_payments.value)),
        sobraCobrar: Math.max(0, redondear2(total_payments.value - totalAPagar.value)),
        efectivoRepetido: sale_payments.value.filter(p => p.method_payment === 'EFECTIVO').length > 1,
        adelantosExceden: adelantosExceden.value,
        detraccionSinCodigo: condicion_especial.value === '2' && !codigo_detraccion_sel.value,
        creditoSinCronograma: cronograma.value.length === 0,
        creditoNoCalza: Math.abs(sumaCronograma() - montoAFinanciar()) > 0.01,
        moraSinTasa: aplica_mora.value && (!tasa_mora.value || tasa_mora.value <= 0),
    });
});
const textoBotonCobrar = computed(() =>
    esCredito.value ? 'Guardar venta a crédito' : `Cobrar ${dinero(totalAPagar.value)}`);

const cobrar = () => {
    if (motivoCobro.value || guardando.value) return;
    store();
};
// Ctrl + Enter cobra desde cualquier campo.
const atajoCobrar = (e: KeyboardEvent) => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); cobrar(); }
};

// ── Cabecera con etiquetas de estado ───────────────────────────────
const nombreSucursal = computed(() => branches.value.find(b => b.id === branch_id_seleccionado.value)?.name ?? '');
const noHabituales = computed(() => (condicion_especial.value !== '0' ? 1 : 0) + (currency.value !== 'PEN' ? 1 : 0));
const etiquetaCondicion = computed(() => ({
    anticipo: 'Con adelanto', exportacion: 'Exportación', '1': 'Retención 3%', '2': 'Detracción', '3': 'Percepción',
} as Record<string, string>)[condicion_especial.value] ?? '');

const cerrarDatos = () => {
    datosAbiertos.value = false;
    buscador.value?.enfocar();
};

// Abrir caja en otra pestaña para no perder la venta armada; al volver a esta pestaña se vuelve a consultar.
const abrirCaja = () => {
    window.open(router.resolve({ name: 'cash.session' }).href, '_blank');
};
const alVolverALaPestana = () => { if (cajaAbierta.value === false) cargarCaja(); };

onMounted(async () => {
    await Promise.all([config(), loadPaymentMethods(), cargarTiposYSucursales(), cargarCaja()]);
    document.addEventListener('keydown', atajoCobrar);
    window.addEventListener('focus', alVolverALaPestana);

    const fromQuote = route.query.from_quote;
    if (typeof fromQuote === 'string' && fromQuote) {
        await prellenarDesdeCotizacion(fromQuote);
    }
    nextTick(() => buscador.value?.enfocar());
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', atajoCobrar);
    window.removeEventListener('focus', alVolverALaPestana);
});
</script>

<style scoped>
/* Pantalla de caja: productos a la izquierda, cobro fijo a la derecha (T-106). */
.grid-venta { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; }
.panel-cobro { position: sticky; top: 110px; } /* debajo de la barra superior de la plantilla */
@media (max-width: 920px) {
    .grid-venta { grid-template-columns: minmax(0, 1fr); }
    .panel-cobro { position: static; }
}

.chip { display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--bs-border-color); background: var(--bs-body-bg, #fff);
    border-radius: 999px; padding: 3px 10px; font-size: .8rem; color: var(--bs-secondary-color); cursor: pointer; }
.chip b { color: var(--bs-body-color); font-weight: 600; }
.chip:hover { border-color: var(--bs-success); }
.chip-alerta { background: var(--bs-warning-bg-subtle); color: var(--bs-warning-text-emphasis); border-color: transparent; }
span.chip { cursor: default; }
</style>
